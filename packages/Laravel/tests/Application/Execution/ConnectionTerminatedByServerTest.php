<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

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
use Ecotone\Api\Laravel\LaravelConnectionReference;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Test\Ecotone\Laravel\RestoresLaravelHandlers;

/**
 * licence Apache-2.0
 * @internal
 */
final class ConnectionTerminatedByServerTest extends TestCase
{
    use RestoresLaravelHandlers;

    protected function setUp(): void
    {
        if (! str_starts_with((string) getenv('DATABASE_DSN'), 'pgsql')) {
            $this->markTestSkipped('Terminating a Laravel connection from the server side is proved on PostgreSQL');
        }

        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        DB::connection('pgsql')->disconnect();
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

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', self::untilOneMessageIsHandled());

        self::assertSame(1, $ecotone->getGateway(DeadLetterGateway::class)->count());
        self::assertSame([], $ecotone->sendQueryWithRouting('order.shipped'));
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
                LaravelConnectionReference::defaultConnection('pgsql'),
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
        $backendId = DB::connection('pgsql')->selectOne('SELECT pg_backend_pid() AS pid')->pid;

        (new DbalConnectionFactory((string) getenv('DATABASE_DSN')))
            ->createContext()
            ->getDbalConnection()
            ->executeStatement('SELECT pg_terminate_backend(' . (int) $backendId . ')');
    }
}
