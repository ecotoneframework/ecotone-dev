<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use function array_search;
use function array_splice;
use function count;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Tracing\ActiveSpan;
use Ecotone\Tracing\Tracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind as OpenTelemetrySpanKind;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/**
 * licence Apache-2.0
 */
final class OpenTelemetryTracer implements Tracer
{
    /** @var OpenTelemetryActiveSpan[] */
    private array $activeSpans = [];

    public function __construct(
        private TracerProviderInterface $tracerProvider,
        private EcotoneClockInterface $clock,
        private SpanRecorder $spanRecorder,
    ) {
    }

    public function startSpan(string $name, SpanKind $kind, array $attributes = [], ?SpanContext $remoteParent = null): ActiveSpan
    {
        $spanBuilder = $this->spanBuilderFor($name, $kind, $attributes);
        if ($remoteParent !== null) {
            $spanBuilder->setParent(TraceContextPropagator::getInstance()->extract([MessageHeaders::TRACEPARENT => $remoteParent->toTraceparent(), MessageHeaders::TRACESTATE => $remoteParent->traceState]));
        }

        return $this->start($spanBuilder, $name, $kind, $attributes, $remoteParent ?? $this->currentSpan()?->context());
    }

    public function startRootSpan(string $name, SpanKind $kind, array $attributes = []): ActiveSpan
    {
        return $this->start($this->spanBuilderFor($name, $kind, $attributes)->setParent(false), $name, $kind, $attributes, null);
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function spanBuilderFor(string $name, SpanKind $kind, array $attributes): SpanBuilderInterface
    {
        return $this->tracerProvider
            ->getTracer(EcotoneSpanBuilder::ECOTONE_TRACER_NAME)
            ->spanBuilder($name)
            ->setSpanKind(self::kindOf($kind))
            ->setAttributes($attributes);
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function start(SpanBuilderInterface $spanBuilder, string $name, SpanKind $kind, array $attributes, ?SpanContext $parentContext): ActiveSpan
    {
        $parentSpanId = $parentContext?->spanId;
        $span = $spanBuilder->startSpan();
        $scope = $span->activate();
        $context = $span->getContext();
        $spanContext = $context->isValid() && $span->isRecording()
            ? new SpanContext($context->getTraceId(), $context->getSpanId(), $context->isSampled(), (string) $context->getTraceState())
            : ($parentContext?->newChild() ?? SpanContext::newTrace());

        $activeSpan = new OpenTelemetryActiveSpan(
            $span,
            $scope,
            $spanContext,
            $parentSpanId,
            $name,
            $kind,
            $attributes,
            $this->clock->now(),
            $this->clock,
            $this,
        );
        $this->activeSpans[] = $activeSpan;

        return $activeSpan;
    }

    public function currentSpan(): ?ActiveSpan
    {
        return $this->activeSpans[count($this->activeSpans) - 1] ?? null;
    }

    public function endSpan(OpenTelemetryActiveSpan $activeSpan, Span $span): void
    {
        $position = array_search($activeSpan, $this->activeSpans, true);
        if ($position !== false) {
            array_splice($this->activeSpans, $position, 1);
        }

        $this->spanRecorder->record($span);
    }

    private static function kindOf(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Internal => OpenTelemetrySpanKind::KIND_INTERNAL,
            SpanKind::Server => OpenTelemetrySpanKind::KIND_SERVER,
            SpanKind::Client => OpenTelemetrySpanKind::KIND_CLIENT,
            SpanKind::Producer => OpenTelemetrySpanKind::KIND_PRODUCER,
            SpanKind::Consumer => OpenTelemetrySpanKind::KIND_CONSUMER,
        };
    }
}
