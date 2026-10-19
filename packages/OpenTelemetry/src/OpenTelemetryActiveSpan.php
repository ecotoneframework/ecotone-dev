<?php

declare(strict_types=1);

namespace Ecotone\OpenTelemetry;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanEvent;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Tracing\ActiveSpan;
use Ecotone\Tracing\EcotoneActiveSpan;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;

/**
 * licence Apache-2.0
 */
final class OpenTelemetryActiveSpan implements ActiveSpan
{
    /** @var SpanEvent[] */
    private array $events = [];
    private bool $ended = false;
    private bool $unitOfWorkFailed = false;
    private bool $childrenStarted = false;

    /**
     * @param array<string, scalar> $attributes
     */
    public function __construct(
        private SpanInterface $span,
        private ScopeInterface $scope,
        private SpanContext $context,
        private ?string $parentSpanId,
        private string $name,
        private SpanKind $kind,
        private array $attributes,
        private DatePoint $startedAt,
        private EcotoneClockInterface $clock,
        private OpenTelemetryTracer $tracer,
    ) {
    }

    public function attributes(): array
    {
        return $this->attributes;
    }

    public function setAttribute(string $key, int|float|string|bool $value): void
    {
        $this->span->setAttribute($key, $value);
        $this->attributes[$key] = $value;
    }

    public function childStarted(): void
    {
        $this->childrenStarted = true;
    }

    public function hasStartedChildren(): bool
    {
        return $this->childrenStarted;
    }

    public function context(): SpanContext
    {
        return $this->context;
    }

    public function addEvent(string $name, array $attributes = []): void
    {
        $this->span->addEvent($name, $attributes);
        $this->events[] = new SpanEvent($name, $attributes, $this->clock->now());
    }

    public function end(): void
    {
        if ($this->unitOfWorkFailed) {
            $this->finish(StatusCode::STATUS_ERROR, SpanStatus::Error, EcotoneActiveSpan::UNIT_OF_WORK_FAILED);

            return;
        }
        $this->finish(StatusCode::STATUS_OK, SpanStatus::Ok, null);
    }

    public function endedSoFar(): Span
    {
        return new Span(
            $this->context->traceId,
            $this->context->spanId,
            $this->parentSpanId,
            $this->name,
            $this->kind,
            $this->attributes,
            SpanStatus::Ok,
            null,
            $this->events,
            $this->startedAt,
            $this->clock->now(),
        );
    }

    public function markUnitOfWorkFailed(): void
    {
        $this->unitOfWorkFailed = true;
    }

    public function markUnitOfWorkRecovered(): void
    {
        $this->unitOfWorkFailed = false;
    }

    public function fail(Throwable $failure): void
    {
        if ($this->ended) {
            return;
        }

        $this->span->recordException($failure);
        $this->events[] = new SpanEvent('exception', [
            'exception.type' => $failure::class,
            'exception.message' => $failure->getMessage(),
            'exception.stacktrace' => $failure->getTraceAsString(),
        ], $this->clock->now());
        $this->finish(StatusCode::STATUS_ERROR, SpanStatus::Error, $failure->getMessage());
    }

    private function finish(string $statusCode, SpanStatus $status, ?string $statusDescription): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        $this->span->setStatus($statusCode, $statusDescription);
        $this->scope->detach();
        $this->span->end();

        $this->tracer->endSpan($this, new Span(
            $this->context->traceId,
            $this->context->spanId,
            $this->parentSpanId,
            $this->name,
            $this->kind,
            $this->attributes,
            $status,
            $statusDescription,
            $this->events,
            $this->startedAt,
            $this->clock->now(),
        ));
    }
}
