<?php

declare(strict_types=1);

namespace Test\Ecotone\Tracing;

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
final class ExecutionRecordingExplanationTest extends TracingTestCase
{
    public function test_the_gatherer_takes_in_a_part_by_its_headers_stores_it_and_hands_the_full_group_to_the_gathered_handler(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        $expected = [
            'process 5: receive backfill, handled by the gatherer taking in part 1 of 2',
            'handler GatheringMessageHandler::handle, chosen by the licence',
            'read part 1 of 2 of gathering group {generated#1} from the headers ecotone_gathering_group_id, ecotone_gathering_part_number and ecotone_gathering_group_size',
            'complete part 1 of gathering group {generated#1} in InMemoryGatheredGroupStore',
            'the group is not complete yet, so nothing is handed over: this process ends here and the remaining parts are awaited',
            'process 6: receive backfill, handled by the gatherer taking in part 2 of 2',
            'handler GatheringMessageHandler::handle, chosen by the licence',
            'read part 2 of 2 of gathering group {generated#1} from the headers ecotone_gathering_group_id, ecotone_gathering_part_number and ecotone_gathering_group_size',
            'complete part 2 of gathering group {generated#1} in InMemoryGatheredGroupStore',
            'all parts are in, so the store releases the group and calls the gatherer back',
            'release 2 of 2 parts, as all parts are accounted for: build GatheredParts with 2 results and the original payload array, passed straight to the handler the #[Gathered] endpoint wraps',
            'handler ProjectionRunCompletion::complete returns nothing',
        ];

        self::assertSame($expected, self::inOrderOf(array_map(trim(...), $lines), $expected));
    }

    public function test_the_gatherer_steps_nest_as_the_code_calls_them(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');
        $complete = self::positionOf($lines, 'complete part 2 of gathering group {generated#1} in InMemoryGatheredGroupStore');

        self::assertSame(self::depthOf($lines[$complete - 1]), self::depthOf($lines[$complete]));
        self::assertSame(self::depthOf($lines[$complete]) + 1, self::depthOf($lines[$complete + 1]));
        self::assertSame(self::depthOf($lines[$complete + 1]) + 1, self::depthOf($lines[$complete + 2]));
        self::assertSame(self::depthOf($lines[$complete + 2]) + 1, self::depthOf($lines[$complete + 3]));
    }

    public function test_no_step_names_a_class_of_the_recording(): void
    {
        $lines = self::executionOf('tag-partitioned projection backfilled asynchronously in two batches');

        self::assertSame([], array_values(array_filter($lines, fn (string $line): bool => preg_match('/\bRecorded[A-Z]\w*\b/', $line) === 1)));
    }

    /**
     * @param list<string> $lines
     */
    private static function positionOf(array $lines, string $trimmed): int
    {
        $position = array_search($trimmed, array_map(trim(...), $lines), true);
        self::assertIsInt($position, "No step {$trimmed}");

        return $position;
    }

    private static function depthOf(string $line): int
    {
        return intdiv(strspn($line, ' '), 2);
    }

    /**
     * @param list<string> $lines
     * @param list<string> $expected
     * @return list<string>
     */
    private static function inOrderOf(array $lines, array $expected): array
    {
        $found = [];
        foreach ($lines as $line) {
            if (($expected[count($found)] ?? null) === $line) {
                $found[] = $line;
            }
        }

        return $found;
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

        return FlowExecution::of($recorder->recordedSpans())->lines();
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

            return FlowExecution::of($recorder->recordedSpans())->lines();
        }
        self::fail("No scenario named {$scenarioName} in the flow scenario catalogue");
    }
}
