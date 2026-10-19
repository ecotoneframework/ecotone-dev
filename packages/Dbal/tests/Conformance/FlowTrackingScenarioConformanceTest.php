<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Tracing\InMemorySpanRecorder;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\TracingConfiguration;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\LicenceTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use Test\Ecotone\Dbal\DbalMessagingTestCase;

/**
 * licence Apache-2.0
 * @internal
 */
final class FlowTrackingScenarioConformanceTest extends DbalMessagingTestCase
{
    private const KNOWN_GAPS = [
        'event forwarded to a channel whose interceptor filters it out' => 'A channel interceptor that filters the message out is reported to the tracing interceptor as a completed send, so the flow waits for a consumer that never comes.',
        'event forwarded to a channel whose interceptor rebuilds the message without framework headers' => 'An interceptor that rebuilds the message without framework headers drops the trace carrier with them, so the consumer starts a new trace and the original flow cannot complete.',
    ];

    public static function scenariosOnEveryStore(): iterable
    {
        foreach (['in-memory', 'dbal'] as $implementation) {
            foreach (FlowScenarioCatalogue::all() as $scenario) {
                yield "{$scenario->name} [{$implementation}]" => [$scenario, $implementation];
            }
        }
    }

    #[DataProvider('scenariosOnEveryStore')]
    public function test_every_catalogued_flow_stores_its_spans_except_failed_ones_and_completes_after_its_last_stage_unless_it_failed(FlowScenario $scenario, string $implementation): void
    {
        if (isset(self::KNOWN_GAPS[$scenario->name])) {
            self::markTestSkipped(self::KNOWN_GAPS[$scenario->name]);
        }
        $recorder = new InMemorySpanRecorder();
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: $scenario->classesToResolve,
            containerOrAvailableServices: [...$scenario->services, InMemorySpanRecorder::class => $recorder, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [
                FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory'),
                TracingConfiguration::createWithDefaults()->withSpanRecorder(InMemorySpanRecorder::class),
                ...$scenario->extensionObjects,
            ],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
        $tracing = $ecotone->getGateway(TracingGateway::class);
        $stored = $scenario->withoutFailedSpans();

        foreach (array_keys($scenario->stages) as $index) {
            self::assertNull($scenario->runStage($index, $ecotone));

            $traceIds = array_values(array_unique(array_map(fn (Span $span): string => $span->traceId, $recorder->recordedSpans())));
            $steps = [];
            foreach ($traceIds as $traceId) {
                $steps = [...$steps, ...($tracing->fetchFlow($traceId)?->steps ?? [])];
            }
            self::assertNull($stored->mismatchAfterStage($index, $steps));
            $completed = array_map(fn (string $traceId): bool => $tracing->isCompletedFor($traceId), $traceIds);
            if ($index === array_key_last($scenario->stages) && ! $scenario->endsInFailure()) {
                self::assertNotContains(false, $completed);
            } else {
                self::assertContains(false, $completed);
            }
        }
    }
}
