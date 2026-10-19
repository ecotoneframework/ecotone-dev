<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Integration;

use Ecotone\Api\Lite\EcotoneLite;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Test\Flow\FlowScenario;
use Ecotone\Test\Flow\FlowScenarioCatalogue;
use Ecotone\Test\LicenceTesting;
use OpenTelemetry\API\Trace\SpanKind as OpenTelemetrySpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProviderExternal;

/**
 * licence Apache-2.0
 * @internal
 */
final class OpenTelemetryFlowConformanceTest extends TracingTestCase
{
    #[DataProviderExternal(FlowScenarioCatalogue::class, 'dataProvider')]
    public function test_every_catalogued_flow_is_exported_as_its_expected_spans(FlowScenario $scenario): void
    {
        $exporter = new InMemoryExporter();
        $ecotone = EcotoneLite::bootstrapFlowTesting(
            $scenario->classesToResolve,
            [...$scenario->services, TracerProviderInterface::class => self::prepareTracer($exporter)],
            $scenario->extensionObjects,
            licenceKey: LicenceTesting::VALID_LICENCE,
        );

        foreach ($scenario->stages as $index => $stage) {
            ($stage->action)($ecotone, []);

            self::assertNull($scenario->mismatchAfterStage($index, array_map(self::asSpan(...), $exporter->getSpans())));
        }
    }

    private static function asSpan(SpanDataInterface $exported): Span
    {
        return new Span(
            $exported->getTraceId(),
            $exported->getSpanId(),
            $exported->getParentContext()->isValid() ? $exported->getParentSpanId() : null,
            $exported->getName(),
            match ($exported->getKind()) {
                OpenTelemetrySpanKind::KIND_SERVER => SpanKind::Server,
                OpenTelemetrySpanKind::KIND_CLIENT => SpanKind::Client,
                OpenTelemetrySpanKind::KIND_PRODUCER => SpanKind::Producer,
                OpenTelemetrySpanKind::KIND_CONSUMER => SpanKind::Consumer,
                default => SpanKind::Internal,
            },
            $exported->getAttributes()->toArray(),
            match ($exported->getStatus()->getCode()) {
                StatusCode::STATUS_OK => SpanStatus::Ok,
                StatusCode::STATUS_ERROR => SpanStatus::Error,
                default => SpanStatus::Unset,
            },
            $exported->getStatus()->getDescription(),
            [],
            DatePoint::createFromTimestamp($exported->getStartEpochNanos() / 1_000_000_000),
            DatePoint::createFromTimestamp($exported->getEndEpochNanos() / 1_000_000_000),
        );
    }
}
