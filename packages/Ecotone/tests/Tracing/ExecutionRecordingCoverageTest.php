<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
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
