<?php

declare(strict_types=1);

namespace Ecotone\Test\Flow\Snapshot;

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\LicenceTesting;

/**
 * licence Apache-2.0
 */
final class FlowScenarioRecorder
{
    /**
     * @return array<string, FlowSnapshot> file name => snapshot
     */
    public static function snapshotsOfTheCatalogue(): array
    {
        $snapshots = [];
        foreach (FlowScenarioCatalogue::all() as $scenario) {
            $snapshot = self::snapshotOf($scenario);
            if (isset($snapshots[$snapshot->fileName()])) {
                throw InvalidArgumentException::create("Scenarios `{$snapshots[$snapshot->fileName()]->scenario}` and `{$scenario->name}` would both be stored as {$snapshot->fileName()}, because a snapshot file is named after the letters and digits of its scenario. Rename one of the two scenarios in the flow scenario catalogue.");
            }
            $snapshots[$snapshot->fileName()] = $snapshot;
        }
        ksort($snapshots, SORT_STRING);

        return $snapshots;
    }

    /**
     * @return array<string, string> file name => what the file holds
     */
    public static function filesOfTheCatalogue(): array
    {
        return FlowSnapshots::filesOf(self::snapshotsOfTheCatalogue(), self::snapshotOf(new FlowScenario('application without handlers', [], [], [], []))->wiring);
    }

    public static function snapshotOf(FlowScenario $scenario): FlowSnapshot
    {
        $spanRecorder = new InMemorySpanRecorder();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            $scenario->classesToResolve,
            [...$scenario->services, InMemorySpanRecorder::class => $spanRecorder],
            [...$scenario->extensionObjects, TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'internal' => true]);
        $wiring = MessageFlowGraph::fromJson($ecotone->getInMemoryConsoleWriter()->getWrittenContent());
        $spanRecorder->clear();
        foreach (array_keys($scenario->stages) as $stageIndex) {
            $failure = $scenario->runStage($stageIndex, $ecotone);
            if ($failure !== null) {
                throw InvalidArgumentException::create("Scenario `{$scenario->name}` cannot be recorded, because it did not run as the flow scenario catalogue describes it: {$failure}. Fix the scenario or the change that broke it; the tracing conformance tests run the same catalogue.");
            }
        }

        return FlowSnapshot::of($scenario->name, $wiring, $spanRecorder->recordedSpans());
    }
}
