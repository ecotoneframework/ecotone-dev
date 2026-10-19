<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\OpenTelemetry\Test\ExportedSpans;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\LicenceTesting;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProviderExternal;

/**
 * licence Apache-2.0
 * @internal
 */
final class OpenTelemetryFlowConformanceTest extends TracingTestCase
{
    #[DataProviderExternal(FlowScenarioCatalogue::class, 'dataProvider')]
    public function test_every_catalogued_flow_is_exported_as_its_expected_spans_without_leaving_a_scope_attached(FlowScenario $scenario): void
    {
        $exporter = new InMemoryExporter();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            $scenario->classesToResolve,
            [...$scenario->services, TracerProviderInterface::class => self::prepareTracer($exporter)],
            $scenario->extensionObjects,
            enabledModules: [ModulePackageList::TRACING_PACKAGE],
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        $scopeNotices = [];
        set_error_handler(static function (int $errorNumber, string $errorMessage) use (&$scopeNotices): bool {
            $scopeNotices[] = $errorMessage;

            return true;
        }, E_USER_NOTICE);
        try {
            foreach (array_keys($scenario->stages) as $index) {
                self::assertNull($scenario->runStage($index, $ecotone));

                self::assertNull($scenario->mismatchAfterStage($index, ExportedSpans::of($exporter)));
            }
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $scopeNotices);
    }
}
