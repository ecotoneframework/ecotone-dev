<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Router;
use Ecotone\Api\Attribute\Splitter;
use Ecotone\Api\Attribute\Transformer;
use Ecotone\Api\ExtensionObject\ExecutionPollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\Flow\Snapshot\FlowExecution;
use Ecotone\Test\LicenceTesting;

/**
 * licence Apache-2.0
 * @internal
 */
final class ExecutionRecordingCoverageTest extends TracingTestCase
{
    public function test_the_route_a_command_or_query_bus_selects_is_recorded_with_why(): void
    {
        $orders = new class () {
            #[CommandHandler(endpointId: 'placeOrder')]
            public function place(ExecutionRecordedPlaceOrder $command): void
            {
            }

            #[QueryHandler('order.count', endpointId: 'countOrders')]
            public function count(): int
            {
                return 1;
            }
        };

        $lines = $this->recorded([$orders::class, ExecutionRecordedPlaceOrder::class], [$orders], [], function (FlowTestSupport $ecotone): void {
            $ecotone->sendCommand(new ExecutionRecordedPlaceOrder());
            $ecotone->sendQueryWithRouting('order.count');
        });

        self::assertContains('route by class ExecutionRecordedPlaceOrder to placeOrder.target', $lines);
        self::assertContains('route by name order.count to countOrders.target', $lines);
    }

    public function test_transformers_splitters_routers_bridges_and_orchestrators_are_recorded_in_their_own_role(): void
    {
        $endpoints = new class () {
            #[Transformer('order.normalize')]
            public function normalize(string $order): string
            {
                return strtoupper($order);
            }

            #[Router('order.route')]
            public function route(string $order): string
            {
                return 'order.normalize';
            }

            #[Splitter('order.split', outputChannelName: 'order.normalize')]
            public function split(string $orders): array
            {
                return explode(',', $orders);
            }

            #[Asynchronous('orders')]
            #[CommandHandler('order.place', endpointId: 'placeOrder')]
            public function place(string $order): void
            {
            }
        };

        $lines = $this->recorded([$endpoints::class], [$endpoints], [SimpleMessageChannelBuilder::createQueueChannel('orders')], function (FlowTestSupport $ecotone): void {
            $ecotone->sendDirectToChannel('order.route', 'b');
            $ecotone->sendDirectToChannel('order.split', 'c,d');
            $ecotone->sendCommandWithRouting('order.place', 'e');
            $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup());
        });

        self::assertContains('router class@anonymous::route returns string', $lines);
        self::assertContains('route to order.normalize', $lines);
        self::assertContains('transformer class@anonymous::normalize returns string', $lines);
        self::assertContains('splitter class@anonymous::split returns array', $lines);
        self::assertCount(3, array_keys($lines, 'direct channel order.normalize', true));
        self::assertContains('bridge Bridge::handle returns a message of string', $lines);
        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => str_starts_with($line, 'handler class@anonymous::normalize') || str_starts_with($line, 'handler class@anonymous::split'))));
        self::assertNotEmpty(array_filter(self::executionOf('orchestrator gateway starting the flow'), fn (string $line): bool => str_starts_with($line, 'orchestrator ')));
    }

    /**
     * @param list<class-string> $classes
     * @param list<object> $services
     * @param list<object> $extensionObjects
     * @param callable(FlowTestSupport): void $run
     * @return list<string>
     */
    private function recorded(array $classes, array $services, array $extensionObjects, callable $run): array
    {
        $recorder = new InMemorySpanRecorder();
        $run(EcotoneLite::bootstrapFlowTesting(
            $classes,
            [...$services, ...self::recordingSpansInto($recorder)],
            [...$extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        ));

        return array_map(trim(...), FlowExecution::of($recorder->recordedSpans())->lines());
    }

    /**
     * @return list<string>
     */
    private static function executionOf(string $scenarioName): array
    {
        foreach (FlowScenarioCatalogue::all() as $scenario) {
            if ($scenario->name !== $scenarioName) {
                continue;
            }
            $recorder = new InMemorySpanRecorder();
            $ecotone = EcotoneLite::bootstrapFlowTesting(
                $scenario->classesToResolve,
                [...$scenario->services, ...self::recordingSpansInto($recorder)],
                [...$scenario->extensionObjects, self::tracingConfiguration()->withExecutionRecording()],
                enabledModules: [],
                licenceKey: LicenceTesting::VALID_LICENCE,
            );
            foreach (array_keys($scenario->stages) as $stage) {
                self::assertNull($scenario->runStage($stage, $ecotone));
            }

            return array_map(trim(...), FlowExecution::of($recorder->recordedSpans())->lines());
        }
        self::fail("No scenario named {$scenarioName} in the flow scenario catalogue");
    }
}

final class ExecutionRecordedPlaceOrder
{
}
