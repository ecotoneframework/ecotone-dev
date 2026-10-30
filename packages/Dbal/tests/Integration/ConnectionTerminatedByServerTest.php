<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Integration;

use Closure;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\Dbal\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Dbal\Gateway\DeadLetterGateway;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use RuntimeException;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

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

    private function bootstrap(object $handler, DbalConnectionFactory $connectionFactory, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler, DbalConnectionFactory::class => $connectionFactory],
            [DbalBackedMessageChannelBuilder::create('orders'), DbalBackedMessageChannelBuilder::create('shipping'), ...$extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../',
        );
    }

    private function ownConnection(): DbalConnectionFactory
    {
        if ($this->isUsingSqlite()) {
            $this->markTestSkipped('SQLite has no server to terminate a connection');
        }

        return new DbalConnectionFactory(getenv('DATABASE_DSN') ?: 'pgsql://ecotone:secret@localhost:5432/ecotone');
    }

    private function terminateServerSideOf(DbalConnectionFactory $connectionFactory): void
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
