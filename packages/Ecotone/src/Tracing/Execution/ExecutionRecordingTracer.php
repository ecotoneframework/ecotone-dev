<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
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
    /** @var array<string, true> span id of an open step => a step ran inside it */
    private array $stepsWithChildren = [];
    /** @var list<Span> steps of the process entry still open, recorded once a span starts inside it */
    private array $stepsOfOpenEntry = [];
    private bool $spanStartedInOpenEntry = false;
    /** @var ?array{name: string, notes: list<array{depth: int, name: string}>, attributes: array<string, scalar>} */
    private ?array $awaitedReceive = null;
    private ?ExecutionStep $receiveEntry = null;
    private ?ExecutionStep $receiveContinuation = null;
    private bool $invocationInOpenEntry = false;
    private bool $housekeepingInOpenEntry = false;
    /** @var array<string, array{depth: int, collected: array<string, true>}> channel => message ids its collector holds while its sender runs */
    private array $collectors = [];
    /** @var list<?string> failure that reached each open interceptor of flow testing, by its short class */
    private array $flowTestingFailures = [];

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
     * @param list<array{depth: int, name: string}> $notes
     * @param array<string, scalar> $attributes
     */
    public function awaitReceive(string $name, array $notes, array $attributes): void
    {
        $this->awaitedReceive = ['name' => $name, 'notes' => $notes, 'attributes' => $attributes];
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function startEntry(string $name, bool $continuesAReceive = false, array $attributes = []): ?ExecutionStep
    {
        if ($this->tracer->currentSpan() !== null || $this->openSteps !== []) {
            return $this->startStep($name, $attributes);
        }
        $awaitedReceive = $continuesAReceive ? $this->awaitedReceive : null;
        if ($awaitedReceive !== null) {
            $this->awaitedReceive = null;
            $this->receiveEntry = $this->startEntry($awaitedReceive['name'], attributes: $awaitedReceive['attributes']);
            $this->recordNested($awaitedReceive['notes']);
            $this->receiveContinuation = $this->startStep($name);

            return $this->receiveContinuation;
        }
        $this->stepsOfOpenEntry = [];
        $this->spanStartedInOpenEntry = false;
        $this->invocationInOpenEntry = false;
        $this->housekeepingInOpenEntry = false;
        $entry = new ExecutionStep(
            SpanContext::newTrace(),
            null,
            null,
            $name,
            [
                SpanAttribute::MESSAGING_SYSTEM => SpanAttribute::ECOTONE,
                SpanAttribute::EXECUTION_STEP => true,
                SpanAttribute::EXECUTION_PROCESS => true,
                SpanAttribute::EXECUTION_ORDER => ++$this->order,
                ...$attributes,
            ],
            $this->clock->now(),
        );
        $this->openSteps[] = $entry;

        return $entry;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function startStep(string $name, array $attributes = [], bool $invocation = false): ?ExecutionStep
    {
        $current = $this->tracer->currentSpan();
        if ($current === null && $this->openSteps === []) {
            return null;
        }
        if ($invocation) {
            $this->invocationInOpenEntry = true;
        }
        $parentStep = $this->openStepOf($current);
        $parentContext = $parentStep?->context ?? $current?->context();
        if ($parentContext === null) {
            return null;
        }
        $this->stepsWithChildren[$parentContext->spanId] = true;
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
     * @param list<array{depth: int, name: string}> $notes
     */
    private function recordNested(array $notes): void
    {
        $open = [];
        foreach ($notes as $note) {
            while (count($open) > $note['depth']) {
                $this->endStep(array_pop($open), '');
            }
            $step = $this->startStep($note['name']);
            if ($step !== null) {
                $open[] = $step;
            }
        }
        while ($open !== []) {
            $this->endStep(array_pop($open), '');
        }
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

    public function endStepWhenSomethingRanInside(ExecutionStep $step): void
    {
        if (isset($this->stepsWithChildren[$step->context->spanId])) {
            $this->endStep($step, '');

            return;
        }
        $position = array_search($step, $this->openSteps, true);
        if ($position !== false) {
            array_splice($this->openSteps, $position, 1);
        }
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
        unset($this->stepsWithChildren[$step->context->spanId]);
        $span = new Span(
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
        );
        if ($step->parentSpanId === null) {
            $this->closeEntry($span);

            return;
        }
        if ($this->receiveEntry !== null && $step === $this->receiveContinuation) {
            $this->stepsOfOpenEntry[] = $span;
            $receiveEntry = $this->receiveEntry;
            $this->receiveEntry = null;
            $this->receiveContinuation = null;
            $this->finish($receiveEntry, '', $status, $statusDescription);

            return;
        }
        if ($this->openSteps !== [] && $this->openSteps[0]->parentSpanId === null) {
            $this->stepsOfOpenEntry[] = $span;

            return;
        }
        $this->spanRecorder->record($span);
    }

    public function openCollector(string $channelName): void
    {
        $collector = $this->collectors[$channelName] ?? ['depth' => 0, 'collected' => []];
        $collector['depth']++;
        $this->collectors[$channelName] = $collector;
    }

    public function closeCollector(string $channelName): void
    {
        if (--$this->collectors[$channelName]['depth'] === 0) {
            unset($this->collectors[$channelName]);
        }
    }

    public function collect(string $channelName, string $messageId): void
    {
        if (isset($this->collectors[$channelName])) {
            $this->collectors[$channelName]['collected'][$messageId] = true;
        }
    }

    public function releaseCollected(string $channelName, string $messageId): bool
    {
        if (! isset($this->collectors[$channelName]['collected'][$messageId])) {
            return false;
        }
        unset($this->collectors[$channelName]['collected'][$messageId]);

        return true;
    }

    public function markHousekeeping(): void
    {
        if (! $this->spanStartedInOpenEntry && ! $this->invocationInOpenEntry) {
            $this->housekeepingInOpenEntry = true;
        }
    }

    public function startFlowTesting(): void
    {
        $this->flowTestingFailures[] = null;
    }

    public function flowTestingFailed(Throwable $failure): void
    {
        if ($this->flowTestingFailures !== []) {
            $this->flowTestingFailures[count($this->flowTestingFailures) - 1] = self::shortClassOf($failure::class);
        }
    }

    public function endFlowTesting(): ?string
    {
        return array_pop($this->flowTestingFailures);
    }

    private function closeEntry(Span $entry): void
    {
        $kept = $entry->status === SpanStatus::Error || (! $this->housekeepingInOpenEntry && ($this->spanStartedInOpenEntry || $this->invocationInOpenEntry));
        if ($kept) {
            foreach ([...$this->stepsOfOpenEntry, $entry] as $span) {
                $this->spanRecorder->record($span);
            }
        }
        $this->stepsOfOpenEntry = [];
        $this->spanStartedInOpenEntry = false;
        $this->invocationInOpenEntry = false;
        $this->housekeepingInOpenEntry = false;
    }

    private function openStepOf(?ActiveSpan $current): ?ExecutionStep
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
        if ($current === null && $this->openSteps === []) {
            $attributes[SpanAttribute::EXECUTION_PROCESS] = true;

            return $attributes;
        }
        if ($current === null) {
            $this->spanStartedInOpenEntry = true;
        }
        $step = $this->openStepOf($current);
        if ($step !== null) {
            $attributes[SpanAttribute::EXECUTION_PARENT] = $step->context->spanId;
        }

        return $attributes;
    }
}
