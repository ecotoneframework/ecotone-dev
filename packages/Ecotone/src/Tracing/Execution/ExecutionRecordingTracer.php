<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_search;
use function array_splice;
use function count;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Tracing\ActiveSpan;
use Ecotone\Tracing\EcotoneTracer;
use Ecotone\Tracing\Tracer;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ExecutionRecordingTracer implements Tracer
{
    /** @var list<ExecutionStep> */
    private array $openSteps = [];
    private int $order = 0;

    public function __construct(
        private EcotoneTracer $tracer,
        private EcotoneClockInterface $clock,
        private SpanRecorder $spanRecorder,
    ) {
    }

    public function startSpan(string $name, SpanKind $kind, array $attributes = [], ?SpanContext $remoteParent = null): ActiveSpan
    {
        return $this->tracer->startSpan($name, $kind, $this->withExecutionAttributes($attributes), $remoteParent);
    }

    public function startRootSpan(string $name, SpanKind $kind, array $attributes = []): ActiveSpan
    {
        return $this->tracer->startRootSpan($name, $kind, $this->withExecutionAttributes($attributes));
    }

    public function currentSpan(): ?ActiveSpan
    {
        return $this->tracer->currentSpan();
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function startStep(string $name, array $attributes = []): ?ExecutionStep
    {
        $current = $this->tracer->currentSpan();
        if ($current === null) {
            return null;
        }
        $parentStep = $this->openStepOf($current);
        $parentContext = $parentStep?->context ?? $current->context();
        $step = new ExecutionStep(
            $parentContext->newChild(),
            $parentContext->spanId,
            $current,
            $name,
            [
                SpanAttribute::MESSAGING_SYSTEM => SpanAttribute::ECOTONE,
                SpanAttribute::EXECUTION_STEP => true,
                SpanAttribute::EXECUTION_ORDER => ++$this->order,
                ...$attributes,
            ],
            $this->clock->now(),
        );
        $this->openSteps[] = $step;

        return $step;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function recordStep(string $name, array $attributes = []): void
    {
        $step = $this->startStep($name, $attributes);
        if ($step !== null) {
            $this->endStep($step, '');
        }
    }

    public function endStep(ExecutionStep $step, string $outcome): void
    {
        $this->finish($step, $outcome, SpanStatus::Ok, null);
    }

    public function failStep(ExecutionStep $step, Throwable $failure): void
    {
        $this->finish($step, 'throws ' . self::shortClassOf($failure::class), SpanStatus::Error, $failure->getMessage());
    }

    public static function shortClassOf(string $class): string
    {
        $class = explode("\0", $class, 2)[0];

        return substr($class, (int) strrpos('\\' . $class, '\\'));
    }

    private function finish(ExecutionStep $step, string $outcome, SpanStatus $status, ?string $statusDescription): void
    {
        $position = array_search($step, $this->openSteps, true);
        if ($position === false) {
            return;
        }
        array_splice($this->openSteps, $position, 1);
        $this->spanRecorder->record(new Span(
            $step->context->traceId,
            $step->context->spanId,
            $step->parentSpanId,
            $step->name,
            SpanKind::Internal,
            $outcome === '' ? $step->attributes : [...$step->attributes, SpanAttribute::EXECUTION_OUTCOME => $outcome],
            $status,
            $statusDescription,
            [],
            $step->startedAt,
            $this->clock->now(),
        ));
    }

    private function openStepOf(ActiveSpan $current): ?ExecutionStep
    {
        $step = $this->openSteps[count($this->openSteps) - 1] ?? null;

        return $step !== null && $step->owner === $current ? $step : null;
    }

    /**
     * @param array<string, scalar> $attributes
     * @return array<string, scalar>
     */
    private function withExecutionAttributes(array $attributes): array
    {
        $attributes[SpanAttribute::EXECUTION_ORDER] = ++$this->order;
        $current = $this->tracer->currentSpan();
        if ($current === null) {
            $attributes[SpanAttribute::EXECUTION_PROCESS] = true;

            return $attributes;
        }
        $step = $this->openStepOf($current);
        if ($step !== null) {
            $attributes[SpanAttribute::EXECUTION_PARENT] = $step->context->spanId;
        }

        return $attributes;
    }
}
