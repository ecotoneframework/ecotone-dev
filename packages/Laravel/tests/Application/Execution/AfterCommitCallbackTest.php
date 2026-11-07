<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Application\Execution;

use Doctrine\DBAL\Connection;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\ExtensionObject\DbalBackedMessageChannelBuilder;
use Ecotone\Api\Dbal\ExtensionObject\DbalConfiguration;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\InstantRetryConfiguration;
use Ecotone\Api\ExtensionObject\ModulePackageList;
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
final class AfterCommitCallbackTest extends TestCase
{
    use RestoresLaravelHandlers;

    protected function setUp(): void
    {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        if (! preg_match('/^(pgsql|mysql)/', (string) getenv('DATABASE_DSN'))) {
            $this->markTestSkipped('Reading what another connection sees needs a database server');
        }

        DB::statement('DROP TABLE IF EXISTS laravel_committed_orders');
        DB::statement('CREATE TABLE laravel_committed_orders (id VARCHAR(255))');
    }

    protected function tearDown(): void
    {
        DB::connection()->disconnect();
    }

    public function test_an_after_commit_callback_registered_in_a_consumed_message_runs_once_its_transaction_committed(): void
    {
        $ecotone = $this->bootstrap($this->orderHandler());

        $ecotone->sendCommandWithRouting('order.place', 'milk');
        $ecotone->run('orders', ExecutionPollingMetadata::createWithFinishWhenNoMessages());

        self::assertSame([['milk']], $ecotone->sendQueryWithRouting('order.committed'));
    }

    public function test_an_after_commit_callback_registered_in_a_transactional_command_runs_once_its_transaction_committed(): void
    {
        $ecotone = $this->bootstrap($this->orderHandler(), [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
        ]);

        $ecotone->sendCommandWithRouting('order.record', 'milk');

        self::assertSame([['milk']], $ecotone->sendQueryWithRouting('order.committed'));
    }

    public function test_an_after_commit_callback_of_a_command_whose_transaction_rolled_back_does_not_run(): void
    {
        $ecotone = $this->bootstrap($this->orderHandler(failing: true), [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
        ]);

        try {
            $ecotone->sendCommandWithRouting('order.record', 'milk');
        } catch (RuntimeException) {
        }

        self::assertSame([], $ecotone->sendQueryWithRouting('order.committed'));
        self::assertSame([], DB::table('laravel_committed_orders')->pluck('id')->all());
    }

    public function test_an_after_commit_callback_that_throws_propagates_its_exception_once_the_transaction_committed(): void
    {
        $ecotone = $this->bootstrap($this->orderHandler(failingAfterCommit: true), [
            DbalConfiguration::createWithDefaults()->withTransactionOnCommandBus(true),
            InstantRetryConfiguration::createWithDefaults()->withCommandBusRetry(false),
        ]);

        try {
            $ecotone->sendCommandWithRouting('order.record', 'milk');
            self::fail('The after commit callback exception did not propagate');
        } catch (RuntimeException $exception) {
            self::assertSame('notification can not be sent', $exception->getMessage());
        }

        self::assertSame([['milk']], $ecotone->sendQueryWithRouting('order.committed'));
    }

    private function orderHandler(bool $failing = false, bool $failingAfterCommit = false): object
    {
        $observer = (new DbalConnectionFactory((string) getenv('DATABASE_DSN')))->createContext()->getDbalConnection();

        return new class ($observer, $failing, $failingAfterCommit) {
            private array $committed = [];

            public function __construct(private Connection $observer, private bool $failing, private bool $failingAfterCommit)
            {
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
                $this->record($order);
            }

            #[CommandHandler('order.record')]
            public function record(string $order): void
            {
                DB::table('laravel_committed_orders')->insert(['id' => $order]);
                DB::afterCommit(function (): void {
                    $this->committed[] = $this->observer->fetchFirstColumn('SELECT id FROM laravel_committed_orders');
                    if ($this->failingAfterCommit) {
                        throw new RuntimeException('notification can not be sent');
                    }
                });
                if ($this->failing) {
                    throw new RuntimeException('order can not be recorded');
                }
            }

            #[QueryHandler('order.committed')]
            public function committed(): array
            {
                return $this->committed;
            }
        };
    }

    private function bootstrap(object $handler, array $extensionObjects = []): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            [$handler::class],
            [$handler],
            [
                LaravelConnectionReference::defaultConnection(DB::getDefaultConnection()),
                DbalBackedMessageChannelBuilder::create('orders'),
                ...$extensionObjects,
            ],
            enabledModules: [ModulePackageList::LARAVEL_PACKAGE, ModulePackageList::DBAL_PACKAGE],
            pathToRootCatalog: __DIR__ . '/../../../',
        );
    }
}
