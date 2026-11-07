<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\PDO\MySQL\Driver as MySQLDriver;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\DBAL\Tools\DsnParser;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\ExtensionObject\TransactionReconnectProtection;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\Dbal\TransactionBoundaryMiddleware;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\DatabaseTransactionLost;
use Ecotone\Dbal\DbalConnection;
use Interop\Queue\ConnectionFactory;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionTerminatedByServerTest extends DbalMessagingTestCase
{
    public function test_a_consumer_handles_the_waiting_message_after_the_server_terminated_its_connection(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler, $connectionFactory);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOf($connectionFactory);
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumer_keeps_running_while_the_database_is_still_starting_after_a_restart(): void
    {
        $restartingDatabase = $this->restartingDatabase();
        $connectionFactory = new DbalConnectionFactory(['dsn' => self::dsn(), 'connection' => ['driverClass' => $restartingDatabase::class]]);
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler, $connectionFactory);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOf($connectionFactory);
        $restartingDatabase::$refusedConnects = 2;
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_message_whose_transaction_lost_the_connection_is_handled_again_and_its_outbox_message_sent_once(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory));
        $ecotone = $this->bootstrap($handler, $connectionFactory);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_failed_message_reaches_the_dead_letter_after_the_server_terminated_the_connection_storing_it(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory), failing: true);
        $ecotone = $this->bootstrap($handler, $connectionFactory, [
            PollingMetadata::create('orders')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
        ]);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());

        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_send_that_lost_the_connection_of_a_transactional_command_bus_is_retried_with_the_whole_command_and_ships_once(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory));
        $ecotone = $this->bootstrap($handler, $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
        ]);

        $ecotone->sendCommandWithRouting('order.dispatch', 'milk');
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_send_that_lost_the_connection_of_a_transactional_command_bus_without_retries_fails_naming_the_way_out(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory));
        $ecotone = $this->bootstrap($handler, $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
        ]);

        $this->expectException(DatabaseTransactionLost::class);
        $this->expectExceptionMessage('The database connection was lost while a message was being sent to `shipping` inside a database transaction, so the message was not stored. It is not retried in place: the transaction it belonged to is gone, and a retry would store the message on a new connection, outside that transaction. The transaction rolls back and the command or consumed message is retried as a whole: the consumer redelivers an asynchronous endpoint\'s message, and the command bus instant retries, on by default when DbalConfiguration runs the command bus in a transaction, retry a command. If they were turned off, turn them back on with InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true), and if they list their retry exceptions, include DatabaseTransactionLost::class. A console command run in a transaction (DbalConfiguration::withTransactionOnConsoleCommands) and a transaction the application opened itself are not retried by Ecotone: run the command again, or retry the work that opened the transaction. To store the message outside the transaction and retry it in place instead, run its handler without a database transaction: mark the handler with #[WithoutDatabaseTransaction] (Ecotone\\Api\\Attribute\\WithoutDatabaseTransaction), or turn the transaction off with DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false), ->withTransactionOnAsynchronousEndpoints(false) or ->withTransactionOnConsoleCommands(false).');

        $ecotone->sendCommandWithRouting('order.dispatch', 'milk');
    }

    public function test_a_send_that_lost_the_connection_outside_a_transaction_is_retried_on_a_fresh_connection(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory));
        $ecotone = $this->bootstrap($handler, $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false),
        ]);

        $ecotone->sendCommandWithRouting('order.dispatch', 'milk');
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumed_message_whose_send_lost_the_transaction_connection_is_handled_again_and_ships_once(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory));
        $ecotone = $this->bootstrap($handler, $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true),
        ]);

        $ecotone->sendCommandWithRouting('order.pack', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumed_message_whose_send_lost_the_connection_outside_a_transaction_is_sent_on_a_fresh_connection_and_ships_once(): void
    {
        $connectionFactory = $this->ownConnection();
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOf($connectionFactory));
        $ecotone = $this->bootstrap($handler, $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(false),
        ]);

        $ecotone->sendCommandWithRouting('order.pack', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_write_after_a_caught_lost_connection_in_a_consumed_message_is_not_stored_outside_its_transaction(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory), $connectionFactory);

        $ecotone->sendCommandWithRouting('order.write', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());

        self::assertSame(['milk'], $this->writtenOrders());
    }

    public function test_a_write_after_a_caught_lost_connection_in_a_transactional_command_is_not_stored_outside_its_transaction(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame(['milk'], $this->writtenOrders());
    }

    public function test_a_reconnect_inside_a_transactional_command_without_retries_fails_naming_the_way_out(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
        ]);

        try {
            $ecotone->sendCommandWithRouting('order.record', 'milk');
            self::fail('The reconnect inside the transaction was not refused');
        } catch (DatabaseTransactionLost $exception) {
            self::assertSame('The database connection was lost while a message was being handled inside a database transaction, so Ecotone refuses to reconnect until the message ends. A new connection would be outside your transaction: part of the message\'s data could be stored while the rest is rolled back, and stored again when the message is retried. The message is rolled back and retried as a whole: the consumer redelivers an asynchronous endpoint\'s message, and the command bus instant retries, on by default when DbalConfiguration runs the command bus in a transaction, retry a command. If they were turned off, turn them back on with InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(true), and if they list their retry exceptions, include DatabaseTransactionLost::class. If the connection failure was first caught and ignored — for example by a middleware in your codebase, or a library that retries automatically — let it reach Ecotone instead. A console command run in a transaction (DbalConfiguration::withTransactionOnConsoleCommands) is not retried by Ecotone: run the command again. To allow the reconnect instead, turn the protection off with DbalConfiguration::createWithDefaults()->withTransactionReconnectProtection(TransactionReconnectProtection::IGNORE) (Ecotone\\Api\\Dbal\\ExtensionObject\\TransactionReconnectProtection), or run the handler without a database transaction: #[WithoutDatabaseTransaction] (Ecotone\\Api\\Attribute\\WithoutDatabaseTransaction), or ->withTransactionOnCommandBus(false), ->withTransactionOnAsynchronousEndpoints(false) or ->withTransactionOnConsoleCommands(false).', $exception->getMessage());
        }

        self::assertSame([], $this->writtenOrders());
    }

    public function test_a_transactional_command_after_the_server_terminated_the_idle_connection_reconnects_at_its_begin_and_is_stored_once(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory, terminatingOnFirstAttempt: false), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'tea');
        $this->terminateServerSideOf($connectionFactory);
        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame(['tea', 'milk'], $this->writtenOrders());
    }

    public function test_a_consumed_message_after_the_server_terminated_the_idle_connection_reconnects_at_its_begin_and_is_stored_once(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory, terminatingOnFirstAttempt: false), $connectionFactory);

        $ecotone->sendCommandWithRouting('order.write', 'milk');
        $this->terminateServerSideOf($connectionFactory);
        $ecotone->run('orders', self::untilOneMessageIsHandled());

        self::assertSame(['milk'], $this->writtenOrders());
    }

    public function test_a_transaction_on_a_connection_built_without_the_transaction_boundary_middleware_is_refused_naming_the_way_out(): void
    {
        $connectionFactory = $this->connectionBuiltByTheApplication([]);
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory, terminatingOnFirstAttempt: false), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
        ]);

        try {
            $ecotone->sendCommandWithRouting('order.record', 'milk');
            self::fail('The transaction on a connection without the transaction boundary middleware was not refused');
        } catch (Throwable $exception) {
            self::assertSame('When the database connection is lost while a message is being handled, the next SQL statement usually fails, and Ecotone rolls the message back and retries it as a whole. In some flows, though — for example a middleware in your codebase, or a library that retries automatically and ignores the connection failure — Doctrine DBAL may reconnect and run the following SQL statements on a new connection, as if nothing had happened. That new connection is outside your transaction, so part of the message\'s data may be stored while the rest is rolled back, and stored again when the message is retried. Ecotone puts stability first, so it prevents this by default: once the connection is lost inside a transaction, it refuses to reconnect until the message ends, and the message is rolled back and retried as a whole. The database connection `Ecotone\\Api\\Dbal\\DbalConnectionFactory` was built without Ecotone\'s TransactionBoundaryMiddleware, so Ecotone cannot provide that protection for it. In your setup, add the middleware where the connection is built, as its last middleware: (new Doctrine\\DBAL\\Configuration())->setMiddlewares([..., new Ecotone\\Api\\Dbal\\TransactionBoundaryMiddleware()]). To allow the reconnect instead, turn the protection off for connections built without the middleware with DbalConfiguration::createWithDefaults()->withTransactionReconnectProtection(TransactionReconnectProtection::WHEN_AVAILABLE) (Ecotone\\Api\\Dbal\\ExtensionObject\\TransactionReconnectProtection), or everywhere with ->withTransactionReconnectProtection(TransactionReconnectProtection::IGNORE). Or run the handler without a database transaction: #[WithoutDatabaseTransaction] (Ecotone\\Api\\Attribute\\WithoutDatabaseTransaction), or ->withTransactionOnCommandBus(false), ->withTransactionOnAsynchronousEndpoints(false) or ->withTransactionOnConsoleCommands(false).', $exception->getMessage());
        }

        self::assertSame([], $this->writtenOrders());
    }

    public function test_a_connection_built_by_the_application_with_the_transaction_boundary_middleware_does_not_store_a_write_outside_its_transaction(): void
    {
        $connectionFactory = $this->connectionBuiltByTheApplication([new TransactionBoundaryMiddleware()]);
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame(['milk'], $this->writtenOrders());
    }

    public function test_a_connection_built_without_the_transaction_boundary_middleware_runs_the_transaction_when_the_protection_is_required_only_when_available(): void
    {
        $connectionFactory = $this->connectionBuiltByTheApplication([]);
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory, terminatingOnFirstAttempt: false), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionReconnectProtection(TransactionReconnectProtection::WHEN_AVAILABLE),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame(['milk'], $this->writtenOrders());
    }

    public function test_an_ignored_transaction_reconnect_protection_lets_a_write_after_a_caught_lost_connection_reconnect_outside_the_transaction(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true)->withTransactionReconnectProtection(TransactionReconnectProtection::IGNORE),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame(['milk', 'milk'], $this->writtenOrders());
    }

    public function test_a_write_after_a_caught_lost_connection_without_a_transaction_reconnects_and_is_stored(): void
    {
        $connectionFactory = $this->ownConnection();
        $ecotone = $this->bootstrap($this->writingOrderHandler($connectionFactory), $connectionFactory, [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(false),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame(['milk'], $this->writtenOrders());
    }

    private static function untilOneMessageIsHandled(): ExecutionPollingMetadata
    {
        return ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 5000, stopOnError: false);
    }

    private function orderHandler(Closure $onFirstAttempt, bool $failing = false): object
    {
        return new class ($onFirstAttempt, $failing) {
            private array $shipped = [];
            private int $attempts = 0;

            public function __construct(private Closure $onFirstAttempt, private bool $failing)
            {
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order, CommandBus $commandBus): void
            {
                $this->attempts++;
                $commandBus->sendWithRouting('order.ship', $order);
                if ($this->attempts === 1) {
                    ($this->onFirstAttempt)();
                }
                if ($this->failing) {
                    throw new RuntimeException('order can not be placed');
                }
            }

            #[CommandHandler('order.dispatch')]
            public function dispatch(string $order, CommandBus $commandBus): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    ($this->onFirstAttempt)();
                }
                $commandBus->sendWithRouting('order.ship', $order);
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.pack', endpointId: 'packOrder')]
            public function pack(string $order, CommandBus $commandBus): void
            {
                $this->attempts++;
                if ($this->attempts === 1) {
                    ($this->onFirstAttempt)();
                }
                $commandBus->sendWithRouting('order.ship', $order);
            }

            #[Asynchronous('shipping')]
            #[CommandHandler('order.ship', endpointId: 'shipOrder')]
            public function ship(string $order): void
            {
                $this->shipped[] = $order;
            }

            #[QueryHandler('order.shipped')]
            public function shipped(): array
            {
                return $this->shipped;
            }
        };
    }

    private function writingOrderHandler(ConnectionFactory $connectionFactory, bool $terminatingOnFirstAttempt = true): object
    {
        $this->getConnection()->executeStatement('DROP TABLE IF EXISTS written_orders');
        $this->getConnection()->executeStatement('CREATE TABLE written_orders (id VARCHAR(255))');

        $terminate = $terminatingOnFirstAttempt ? fn () => $this->terminateServerSideOf($connectionFactory) : null;

        return new class ($connectionFactory->createContext()->getDbalConnection(), $terminate) {
            private int $attempts = 0;

            public function __construct(private Connection $connection, private ?Closure $terminate)
            {
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.write', endpointId: 'writeOrder')]
            public function write(string $order): void
            {
                $this->record($order);
            }

            #[CommandHandler('order.record')]
            public function record(string $order): void
            {
                $this->attempts++;
                if ($this->attempts === 1 && $this->terminate !== null) {
                    ($this->terminate)();
                    try {
                        $this->connection->insert('written_orders', ['id' => $order]);
                    } catch (ConnectionLost) {
                    }
                }
                $this->connection->insert('written_orders', ['id' => $order]);
            }
        };
    }

    private function writtenOrders(): array
    {
        return $this->getConnection()->fetchFirstColumn('SELECT id FROM written_orders');
    }

    private function bootstrap(object $handler, ConnectionFactory $connectionFactory, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $connectionFactory],
            [DbalBackedMessageChannelBuilder::create('orders'), DbalBackedMessageChannelBuilder::create('shipping'), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function connectionBuiltByTheApplication(array $middlewares): ConnectionFactory
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server to terminate a connection');
        }

        return DbalConnection::create(DriverManager::getConnection(
            (new DsnParser(['pgsql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse(self::dsn()),
            (new Configuration())->setMiddlewares($middlewares),
        ));
    }

    private function ownConnection(): DbalConnectionFactory
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server to terminate a connection');
        }

        return new DbalConnectionFactory(self::dsn());
    }

    private function restartingDatabase(): Driver
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server to restart');
        }

        return new class () implements Driver {
            public static int $refusedConnects = 0;
            private Driver $driver;

            public function __construct()
            {
                $this->driver = str_starts_with(ConnectionTerminatedByServerTest::dsn(), 'pgsql') ? new PgSQLDriver() : new MySQLDriver();
            }

            public function connect(array $params): DriverConnection
            {
                if (self::$refusedConnects > 0) {
                    self::$refusedConnects--;
                    $params['port'] = 1;
                }

                return $this->driver->connect($params);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return $this->driver->getDatabasePlatform($versionProvider);
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                return $this->driver->getExceptionConverter();
            }
        };
    }

    public static function dsn(): string
    {
        return getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone';
    }

    private function terminateServerSideOf(ConnectionFactory $connectionFactory): void
    {
        $owned = $connectionFactory->createContext()->getDbalConnection();
        $administrator = $this->getConnection();

        if ($administrator->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $administrator->executeStatement('SELECT pg_terminate_backend(' . (int) $owned->fetchOne('SELECT pg_backend_pid()') . ')');

            return;
        }

        $administrator->executeStatement('KILL ' . (int) $owned->fetchOne('SELECT CONNECTION_ID()'));
    }
}
