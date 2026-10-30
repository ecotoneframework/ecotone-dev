<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Dbal;

use Closure;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tempest\TempestConnectionReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tempest\Container\GenericContainer;
use Tempest\Database\Config\DatabaseConfig;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Connection\Connection;
use Tempest\Database\Connection\PDOConnection;
use Tempest\Database\Migrations\MigrationNamingStrategy;
use Tempest\Database\Tables\NamingStrategy;
use Test\Ecotone\Tempest\TempestDatabaseConfigFactory;
use UnitEnum;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionTerminatedByServerTest extends TestCase
{
    private const CONFIG_TAG = 'terminated';

    private Connection $tempestConnection;

    protected function setUp(): void
    {
        if (! str_starts_with((string) getenv('DATABASE_DSN'), 'pgsql')) {
            $this->markTestSkipped('Terminating a Tempest connection from the server side is proved on PostgreSQL');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->tempestConnection)) {
            $this->tempestConnection->close();
        }
        GenericContainer::setInstance(null);
    }

    public static function connectionReferences(): iterable
    {
        yield 'default connection' => [static fn (): TempestConnectionReference => TempestConnectionReference::defaultConnection(), null];
        yield 'tagged connection' => [static fn (): TempestConnectionReference => TempestConnectionReference::create(self::CONFIG_TAG, DbalConnectionFactory::class), self::CONFIG_TAG];
    }

    #[DataProvider('connectionReferences')]
    public function test_a_consumer_on_a_tempest_connection_handles_the_waiting_message_after_the_server_terminated_its_connection(Closure $connectionReference, ?string $tag): void
    {
        $this->registerTempestConnection($tag);
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler, $connectionReference());

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOfTempestConnection();
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[DataProvider('connectionReferences')]
    public function test_a_consumer_on_a_tempest_connection_keeps_running_while_the_database_is_still_starting_after_a_restart(Closure $connectionReference, ?string $tag): void
    {
        $databaseConfig = $this->registerTempestConnection($tag);
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler, $connectionReference());

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOfTempestConnection();
        $databaseConfig->refusals = 2;
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[DataProvider('connectionReferences')]
    public function test_a_message_whose_transaction_lost_the_tempest_connection_is_handled_again_and_its_outbox_message_sent_once(Closure $connectionReference, ?string $tag): void
    {
        $this->registerTempestConnection($tag);
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOfTempestConnection());
        $ecotone = $this->bootstrap($handler, $connectionReference());

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    #[DataProvider('connectionReferences')]
    public function test_a_failed_message_reaches_the_dead_letter_after_the_server_terminated_the_tempest_connection_storing_it(Closure $connectionReference, ?string $tag): void
    {
        $this->registerTempestConnection($tag);
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOfTempestConnection(), failing: true);
        $ecotone = $this->bootstrap($handler, $connectionReference(), [
            PollingMetadata::create('orders')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
        ]);
        $ecotone->getGateway(DeadLetterGateway::class)->deleteAll();

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());

        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    private static function untilOneMessageIsHandled(): ExecutionPollingMetadata
    {
        return ExecutionPollingMetadata::createWithTestingSetup(handledMessageLimit: 1, executionTimeLimitInMilliseconds: 5000, stopOnError: false);
    }

    private function registerTempestConnection(?string $tag): DatabaseConfig
    {
        $container = new GenericContainer();
        GenericContainer::setInstance($container);

        $databaseConfig = new class (TempestDatabaseConfigFactory::primary($tag)) implements DatabaseConfig {
            public int $refusals = 0;

            public string $dsn {
                get {
                    if ($this->refusals > 0) {
                        $this->refusals--;

                        return 'pgsql:host=' . parse_url((string) getenv('DATABASE_DSN'), PHP_URL_HOST) . ';port=1;dbname=ecotone';
                    }

                    return $this->databaseConfig->dsn;
                }
            }

            public NamingStrategy $namingStrategy {
                get => $this->databaseConfig->namingStrategy;
            }

            public MigrationNamingStrategy $migrationNamingStrategy {
                get => $this->databaseConfig->migrationNamingStrategy;
            }

            public DatabaseDialect $dialect {
                get => $this->databaseConfig->dialect;
            }

            public ?string $username {
                get => $this->databaseConfig->username;
            }

            public ?string $password {
                get => $this->databaseConfig->password;
            }

            public bool $usePersistentConnection {
                get => $this->databaseConfig->usePersistentConnection;
            }

            public array $options {
                get => $this->databaseConfig->options;
            }

            public string|UnitEnum|null $tag {
                get => $this->databaseConfig->tag;
            }

            public function __construct(private DatabaseConfig $databaseConfig)
            {
            }
        };
        $this->tempestConnection = new PDOConnection($databaseConfig);
        $this->tempestConnection->connect();

        $container->singleton(DatabaseConfig::class, $databaseConfig, tag: $tag);
        $container->singleton(Connection::class, $this->tempestConnection, tag: $tag);

        return $databaseConfig;
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

    private function bootstrap(object $handler, TempestConnectionReference $connectionReference, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler],
            [
                $connectionReference,
                DbalBackedMessageChannelBuilder::create('orders'),
                DbalBackedMessageChannelBuilder::create('shipping'),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::TEMPEST_PACKAGE, ModulePackageList::DBAL_PACKAGE],
        );
    }

    private function terminateServerSideOfTempestConnection(): void
    {
        $statement = $this->tempestConnection->prepare('SELECT pg_backend_pid()');
        $statement->execute();
        $backendId = (int) $statement->fetchColumn();

        (new DbalConnectionFactory((string) getenv('DATABASE_DSN')))
            ->createContext()
            ->getDbalConnection()
            ->executeStatement('SELECT pg_terminate_backend(' . $backendId . ')');
    }
}
