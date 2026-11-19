<?php

declare(strict_types=1);

namespace Test\Ecotone\BuildFlow;

use DateTimeImmutable;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Lite\Test\FlowTestSupport;
use Ecotone\Api\Messaging\Flow\MessageFlowGraph;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Api\Tracing\TracingLevel;
use Ecotone\Messaging\Support\InvalidArgumentException;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\LicenceTesting;

/**
 * licence Apache-2.0
 */
final class FlowScenarioRecorder
{
    private const RECORDED_AT = '2026-01-01 12:00:00 UTC';

    /**
     * @return array<string, FlowSnapshot>
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
     * @return array<string, string>
     */
    public static function filesOfTheCatalogue(): array
    {
        return FlowSnapshots::filesOf(self::snapshotsOfTheCatalogue(), self::snapshotOf(new FlowScenario(name: 'application without handlers', classesToResolve: [], services: [], extensionObjects: [], stages: [])));
    }

    public static function snapshotOf(FlowScenario $scenario): FlowSnapshot
    {
        $spanRecorder = new InMemorySpanRecorder();
        $ecotone = self::applicationOf(scenario: $scenario, spanRecorder: $spanRecorder, tracing: TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class)->withLevel(TracingLevel::Framework));
        $ecotone->runConsoleCommand('ecotone:flow:dump', ['format' => 'json', 'build' => true]);
        $dump = $ecotone->getInMemoryConsoleWriter()->getWrittenContent();
        $spanRecorder->clear();
        foreach (array_keys($scenario->stages) as $stageIndex) {
            $failure = $scenario->runStage($stageIndex, $ecotone);
            if ($failure !== null) {
                throw InvalidArgumentException::create("Scenario `{$scenario->name}` cannot be recorded, because it did not run as the flow scenario catalogue describes it: {$failure}. Fix the scenario or the change that broke it; the tracing conformance tests run the same catalogue.");
            }
        }

        return FlowSnapshot::of(scenario: $scenario->name, wiring: MessageFlowGraph::fromJson($dump), spans: $spanRecorder->recordedSpans(), build: json_decode($dump, true, flags: JSON_THROW_ON_ERROR)['build']);
    }

    private static function applicationOf(FlowScenario $scenario, InMemorySpanRecorder $spanRecorder, TracingConfiguration $tracing): FlowTestSupport
    {
        return EcotoneLite::bootstrapFlowTesting(
            classesToResolve: $scenario->classesToResolve,
            containerOrAvailableServices: [...$scenario->services, InMemorySpanRecorder::class => $spanRecorder],
            extensionObjects: [...$scenario->extensionObjects, $tracing],
            enabledModules: [],
            licenceKey: LicenceTesting::VALID_LICENCE,
            initialTime: new DateTimeImmutable(self::RECORDED_AT),
        );
    }
}
