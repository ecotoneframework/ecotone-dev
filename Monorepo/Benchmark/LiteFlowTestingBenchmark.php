<?php

declare(strict_types=1);

namespace Monorepo\Benchmark;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\ServiceConfiguration;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Projecting\FromStream;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Lite\EcotoneLite;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Modelling\Event;
use Ecotone\Test\LicenceTesting;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPUnit\Framework\Assert;

/**
 * licence Apache-2.0
 */
#[Warmup(20), Revs(800), Iterations(10)]
final class LiteFlowTestingBenchmark
{
    private ConfiguredMessagingSystem $executionSystem;
    private int $executed = 0;

    public function setUpExecution(): void
    {
        $handler = new class () {
            public int $commands = 0;
            public int $notifications = 0;

            #[CommandHandler('benchmark.execute')]
            public function execute(string $payload): void
            {
                ++$this->commands;
            }

            #[Asynchronous('execution')]
            #[EventHandler('benchmark.executed', endpointId: 'benchmark.execution.notify')]
            public function notify(string $payload): void
            {
                ++$this->notifications;
            }

            #[QueryHandler('benchmark.counts')]
            public function counts(): array
            {
                return [$this->commands, $this->notifications];
            }
        };
        $this->executionSystem = EcotoneLite::bootstrap(
            [$handler::class],
            [$handler],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('execution'))
        );
        $this->executed = 0;
    }

    #[BeforeMethods('setUpExecution')]
    public function bench_bus_execution(): void
    {
        $this->executionSystem->getCommandBus()->sendWithRouting('benchmark.execute', 'one');
        $this->executionSystem->getEventBus()->publishWithRouting('benchmark.executed', 'one');
        $this->executionSystem->run('execution', ExecutionPollingMetadata::createWithTestingSetup(1));
        ++$this->executed;
        Assert::assertSame([$this->executed, $this->executed], $this->executionSystem->getQueryBus()->sendWithRouting('benchmark.counts'));
    }

    public function bench_bootstrap_only(): void
    {
        $handler = new class () {
            #[CommandHandler('benchmark.bootstrap')]
            public function handle(string $payload): string
            {
                return $payload;
            }
        };
        EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler]);
    }

    public function bench_bootstrap_async_projection(): void
    {
        $projection = new #[Projection('benchmark'), FromStream('benchmark_stream'), Asynchronous('async')] class {
            public array $events = [];

            #[EventHandler('*')]
            public function handle(array $event): void
            {
                $this->events[] = $event;
            }
        };
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            [$projection::class],
            [$projection],
            configuration: ServiceConfiguration::createWithDefaults()
                ->withModulePackages([])
                ->withLicenceKey(LicenceTesting::VALID_LICENCE)
                ->addExtensionObject(SimpleMessageChannelBuilder::createQueueChannel('async'))
        );
        Assert::assertSame([], $projection->events);
        $ecotone->withEvents([
            Event::createWithType('benchmark-event', ['id' => 1]),
            Event::createWithType('benchmark-event', ['id' => 2]),
        ]);
        $ecotone->publishEventWithRouting('trigger', []);
        Assert::assertSame([], $projection->events);
        $ecotone->run('async', ExecutionPollingMetadata::createWithTestingSetup());
        Assert::assertSame([['id' => 1], ['id' => 2]], $projection->events);
    }

    public function bench_bootstrap_send_assert(): void
    {
        $handler = new class () {
            private array $orders = [];
            private array $notifications = [];

            #[CommandHandler('benchmark.place')]
            public function place(string $order): void
            {
                $this->orders[] = $order;
            }

            #[Asynchronous('notifications')]
            #[EventHandler('benchmark.placed', endpointId: 'benchmark.notify')]
            public function notify(string $order): void
            {
                $this->notifications[] = $order;
            }

            #[QueryHandler('benchmark.orders')]
            public function orders(): array
            {
                return $this->orders;
            }

            #[QueryHandler('benchmark.notifications')]
            public function notifications(): array
            {
                return $this->notifications;
            }
        };

        $ecotone = EcotoneLite::bootstrapFlowTesting([$handler::class], [$handler]);
        Assert::assertSame([], $ecotone->sendQueryWithRouting('benchmark.orders'));
        Assert::assertSame([], $ecotone->sendQueryWithRouting('benchmark.notifications'));
        foreach (['one', 'two', 'three'] as $order) {
            $ecotone->sendCommandWithRouting('benchmark.place', $order);
            $ecotone->publishEventWithRouting('benchmark.placed', $order);
        }
        Assert::assertSame([], $ecotone->sendQueryWithRouting('benchmark.notifications'));
        $ecotone->run('notifications');
        Assert::assertSame(['one', 'two', 'three'], $ecotone->sendQueryWithRouting('benchmark.orders'));
        Assert::assertSame(['one', 'two', 'three'], $ecotone->sendQueryWithRouting('benchmark.notifications'));
    }
}
