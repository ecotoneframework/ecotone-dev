<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Conformance;

use Ecotone\Api\Dbal\DbalConnectionFactory;
use Ecotone\Api\Dbal\Tracing\FlowTrackingConfiguration;
use Ecotone\Api\Dbal\Tracing\TracingGateway;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Test\Flow\ExpectedSpan;
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
    public static function scenariosOnEveryStore(): iterable
    {
        foreach (['in-memory', 'dbal'] as $implementation) {
            foreach (FlowScenarioCatalogue::all() as $scenario) {
                yield "{$scenario->name} [{$implementation}]" => [$scenario, $implementation];
            }
        }
    }

    #[DataProvider('scenariosOnEveryStore')]
    public function test_every_catalogued_flow_is_stored_as_its_expected_spans_and_completes_after_its_last_stage(FlowScenario $scenario, string $implementation): void
    {
        $ecotone = $this->bootstrapFlowTesting(
            classesToResolve: $scenario->classesToResolve,
            containerOrAvailableServices: [...$scenario->services, DbalConnectionFactory::class => $this->getConnectionFactory()],
            extensionObjects: [FlowTrackingConfiguration::createWithDefaults()->withInMemoryStorage($implementation === 'in-memory'), ...$scenario->extensionObjects],
            enabledModules: [ModulePackageList::DBAL_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        )->setupDatabaseTables();
        $tracing = $ecotone->getGateway(TracingGateway::class);
        $trace = SpanContext::newTrace();

        foreach ($scenario->stages as $index => $stage) {
            ($stage->action)($ecotone, [MessageHeaders::TRACEPARENT => $trace->toTraceparent()]);

            $steps = $tracing->fetchFlow($trace->traceId)?->steps ?? [];
            self::assertNull(ExpectedSpan::mismatchIn($stage->expectedSpans, $steps));
            foreach ($stage->absentSpanNames as $absentSpanName) {
                self::assertFalse(ExpectedSpan::namedIn($absentSpanName, $steps), "Step {$absentSpanName} was stored");
            }
            self::assertSame($index === array_key_last($scenario->stages), $tracing->isCompletedFor($trace->traceId));
        }
    }
}
