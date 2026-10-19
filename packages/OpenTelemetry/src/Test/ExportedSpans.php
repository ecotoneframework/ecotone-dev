<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry\Test;

use function array_map;

use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanEvent;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;
use OpenTelemetry\API\Trace\SpanKind as OpenTelemetrySpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;

/**
 * licence Apache-2.0
 */
final class ExportedSpans
{
    /**
     * @return list<Span>
     */
    public static function of(InMemoryExporter $exporter): array
    {
        return array_map(self::asSpan(...), $exporter->getSpans());
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
            array_map(
                fn (EventInterface $event) => new SpanEvent($event->getName(), $event->getAttributes()->toArray(), DatePoint::createFromTimestamp($event->getEpochNanos() / 1_000_000_000)),
                $exported->getEvents(),
            ),
            DatePoint::createFromTimestamp($exported->getStartEpochNanos() / 1_000_000_000),
            DatePoint::createFromTimestamp($exported->getEndEpochNanos() / 1_000_000_000),
        );
    }
}
