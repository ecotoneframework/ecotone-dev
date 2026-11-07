<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Closure;
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
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Laravel\RestoresLaravelHandlers;
use Throwable;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionTerminatedByServerTest extends TestCase
{
    use RestoresLaravelHandlers;

    protected function setUp(): void
    {
        if (! preg_match('/^(pgsql|mysql)/', (string) getenv('DATABASE_DSN'))) {
            $this->markTestSkipped('Terminating a Laravel connection from the server side needs a database server');
        }

        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        DB::connection()->disconnect();
    }

    public function test_a_consumer_on_a_laravel_connection_handles_the_waiting_message_after_the_server_terminated_its_connection(): void
    {
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOfLaravelConnection();
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumer_on_a_laravel_connection_reconnects_through_the_connection_laravel_holds_after_the_application_purged_it(): void
    {
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOfLaravelConnection();
        DB::purge(self::connectionName());
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_laravel_transaction_that_reconnected_is_rolled_back_whole_when_a_command_sent_inside_it_finds_the_old_connection_lost(): void
    {
        DB::statement('DROP TABLE IF EXISTS laravel_written_orders');
        DB::statement('CREATE TABLE laravel_written_orders (id VARCHAR(255))');
        $ecotone = $this->bootstrap($this->orderHandler(static function (): void {
        }));
        $ecotone->sendCommandWithRouting('order.place', 'tea');
        $this->terminateServerSideOfLaravelConnection();

        try {
            DB::transaction(static function () use ($ecotone): void {
                DB::table('laravel_written_orders')->insert(['id' => 'milk']);
                $ecotone->sendCommandWithRouting('order.place', 'milk');
            });
        } catch (Throwable) {
        }
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([], DB::table('laravel_written_orders')->pluck('id')->all());
        self::assertSame(['tea'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumer_on_a_laravel_connection_keeps_running_while_the_database_is_still_starting_after_a_restart(): void
    {
        $handler = $this->orderHandler(static function (): void {
        });
        $ecotone = $this->bootstrap($handler);
        $refusals = 2;
        $connectionName = self::connectionName();
        DB::connection()->setReconnector(static function () use (&$refusals, $connectionName): void {
            $port = config("database.connections.{$connectionName}.port");
            if ($refusals-- > 0) {
                config(["database.connections.{$connectionName}.port" => 1]);
            }
            try {
                app('db')->reconnect($connectionName);
            } finally {
                config(["database.connections.{$connectionName}.port" => $port]);
            }
        });

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $this->terminateServerSideOfLaravelConnection();
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_message_whose_transaction_lost_the_laravel_connection_is_handled_again_and_its_outbox_message_sent_once(): void
    {
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOfLaravelConnection());
        $ecotone = $this->bootstrap($handler);

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_failed_message_reaches_the_dead_letter_after_the_server_terminated_the_laravel_connection_storing_it(): void
    {
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOfLaravelConnection(), failing: true);
        $ecotone = $this->bootstrap($handler, [
            PollingMetadata::create('orders')->withErrorChannelName(DbalDeadLetterBuilder::STORE_CHANNEL),
            InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false),
        ]);
        $ecotone->getGateway(DeadLetterGateway::class)->deleteAll();

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());

        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumed_message_whose_send_lost_the_transaction_laravel_connection_is_handled_again_and_ships_once(): void
    {
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOfLaravelConnection());
        $ecotone = $this->bootstrap($handler, [
            DbalConfiguration::createWithDefaults()->withTransactionOnAsynchronousEndpoints(true),
        ]);

        $ecotone->sendCommandWithRouting('order.pack', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());
        $ecotone->run('shipping', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame(['milk'], $ecotone->sendQueryWithRouting('order.shipped'));
    }

    public function test_a_consumed_message_whose_send_lost_the_laravel_connection_outside_a_transaction_is_sent_on_a_fresh_connection_and_ships_once(): void
    {
        $handler = $this->orderHandler(fn () => $this->terminateServerSideOfLaravelConnection());
        $ecotone = $this->bootstrap($handler, [
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

    private function bootstrap(object $handler, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler],
            [
                LaravelConnectionReference::defaultConnection(self::connectionName()),
                DbalBackedMessageChannelBuilder::create('orders'),
                DbalBackedMessageChannelBuilder::create('shipping'),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../../',
        );
    }

    private function terminateServerSideOfLaravelConnection(): void
    {
        $administrator = (new DbalConnectionFactory((string) getenv('DATABASE_DSN')))->createContext()->getDbalConnection();

        if (self::connectionName() === 'pgsql') {
            $administrator->executeStatement('SELECT pg_terminate_backend(' . (int) DB::connection()->selectOne('SELECT pg_backend_pid() AS id')->id . ')');

            return;
        }

        $administrator->executeStatement('KILL ' . (int) DB::connection()->selectOne('SELECT CONNECTION_ID() AS id')->id);
    }

    private static function connectionName(): string
    {
        return DB::getDefaultConnection();
    }
}
