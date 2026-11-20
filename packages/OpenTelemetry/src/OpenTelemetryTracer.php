<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use function array_search;
use function array_splice;
use function count;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Messaging\Execution\ExecutionScope;
use Ecotone\Tracing\ActiveSpan;
use Ecotone\Tracing\Tracer;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanKind as OpenTelemetrySpanKind;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * licence Apache-2.0
 */
final class OpenTelemetryTracer implements Tracer
{
    public const ECOTONE_TRACER_NAME = 'io.opentelemetry.contrib.php.ecotone';

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
            $spanBuilder->setParent(($this->spans()->detached[$remoteParent->spanId] ?? null)?->asParent() ?? self::remoteContextOf($remoteParent));
        }

        $this->currentSpan()?->childStarted();

        return $this->start($spanBuilder, $name, $kind, $attributes, $remoteParent ?? $this->currentSpan()?->context());
    }

    public function startRootSpan(string $name, SpanKind $kind, array $attributes = []): ActiveSpan
    {
        return $this->start($this->spanBuilderFor($name, $kind, $attributes)->setParent(false), $name, $kind, $attributes, null);
    }

    public function startDetachedSpan(string $name, SpanKind $kind, array $attributes, ?SpanContext $parent, DatePoint $startedAt): ActiveSpan
    {
        $spanBuilder = $this->spanBuilderFor($name, $kind, $attributes);
        if ($parent !== null) {
            $spanBuilder->setParent($this->openSpanWithId($parent->spanId)?->asParent() ?? self::remoteContextOf($parent));
        }
        $spanBuilder->setStartTimestamp((int) $startedAt->format('U') * 1_000_000_000 + $startedAt->getMicrosecond() * 1_000);
        $span = $this->spanOf(spanBuilder: $spanBuilder, name: $name, kind: $kind, attributes: $attributes, parentContext: $parent, startedAt: $startedAt, activated: false);
        $this->spans()->detached[$span->context()->spanId] = $span;

        return $span;
    }

    private function openSpanWithId(string $spanId): ?OpenTelemetryActiveSpan
    {
        foreach ($this->spans()->active as $activeSpan) {
            if ($activeSpan->context()->spanId === $spanId) {
                return $activeSpan;
            }
        }

        return $this->spans()->detached[$spanId] ?? null;
    }

    private static function remoteContextOf(SpanContext $parent): ContextInterface
    {
        return TraceContextPropagator::getInstance()->extract([MessageHeaders::TRACEPARENT => $parent->toTraceparent(), MessageHeaders::TRACESTATE => $parent->traceState]);
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function spanBuilderFor(string $name, SpanKind $kind, array $attributes): SpanBuilderInterface
    {
        return $this->tracerProvider
            ->getTracer(self::ECOTONE_TRACER_NAME)
            ->spanBuilder($name)
            ->setSpanKind(self::kindOf($kind))
            ->setAttributes($attributes);
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function start(SpanBuilderInterface $spanBuilder, string $name, SpanKind $kind, array $attributes, ?SpanContext $parentContext): ActiveSpan
    {
        $activeSpan = $this->spanOf(spanBuilder: $spanBuilder, name: $name, kind: $kind, attributes: $attributes, parentContext: $parentContext, startedAt: $this->clock->now(), activated: true);
        $this->spans()->active[] = $activeSpan;

        return $activeSpan;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private function spanOf(SpanBuilderInterface $spanBuilder, string $name, SpanKind $kind, array $attributes, ?SpanContext $parentContext, DatePoint $startedAt, bool $activated): OpenTelemetryActiveSpan
    {
        $parentSpanId = $parentContext?->spanId;
        $span = $spanBuilder->startSpan();
        $scope = $activated ? $span->activate() : null;
        $context = $span->getContext();
        $spanContext = $context->isValid() && $span->isRecording()
            ? new SpanContext($context->getTraceId(), $context->getSpanId(), $context->isSampled(), (string) $context->getTraceState())
            : ($parentContext?->newChild() ?? SpanContext::newTrace());

        return new OpenTelemetryActiveSpan(
            $span,
            $scope,
            $spanContext,
            $parentSpanId,
            $name,
            $kind,
            $attributes,
            $startedAt,
            $this->clock,
            $this,
        );
    }

    public function currentSpan(): ?ActiveSpan
    {
        return $this->spans()->active[count($this->spans()->active) - 1] ?? null;
    }

    public function currentApplicationSpan(): ?ActiveSpan
    {
        return $this->currentSpan();
    }

    public function endSpan(OpenTelemetryActiveSpan $activeSpan, Span $span): void
    {
        $position = array_search($activeSpan, $this->spans()->active, true);
        if ($position !== false) {
            array_splice($this->spans()->active, $position, 1);
        }
        unset($this->spans()->detached[$span->spanId]);

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

    private function spans(): OpenTelemetrySpansOfExecution
    {
        return ExecutionScope::stateOf($this, static fn () => new OpenTelemetrySpansOfExecution());
    }
}
