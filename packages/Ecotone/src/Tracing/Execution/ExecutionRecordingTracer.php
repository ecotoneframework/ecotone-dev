<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use function array_pop;
use function array_search;
use function array_splice;
use function array_unshift;
use function count;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\DatePoint;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanContext;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Messaging\Execution\ExecutionScope;
use Ecotone\Tracing\ActiveSpan;
use Ecotone\Tracing\Tracer;

use function spl_object_id;

use Throwable;

/**
 * licence Apache-2.0
 */
final class ExecutionRecordingTracer implements Tracer
{
    private int $order = 0;

    private const STATE_SUSPENDED_WITH_THE_OPEN_ENTRY = ['openSteps', 'stepsWithChildren', 'stepsOfOpenEntry', 'spanStartedInOpenEntry', 'receiveEntry', 'receiveContinuation', 'invocationInOpenEntry', 'housekeepingInOpenEntry', 'collectors', 'hintsOfOpenSteps'];

    public function __construct(
        private Tracer $tracer,
        private EcotoneClockInterface $clock,
        private StepSpanCapture $spanRecorder,
    ) {
    }

    public function startSpan(string $name, SpanKind $kind, array $attributes = [], ?SpanContext $remoteParent = null): ActiveSpan
    {
        $attributes = $this->withExecutionAttributes($attributes);
        $current = $this->tracer->currentSpan();
        $step = $this->openStepOf($current);
        if ($step !== null && ($remoteParent === null || $remoteParent->traceId === $this->spanOf($step)->context()->traceId)) {
            $attributes[SpanAttribute::EXECUTION_APPLICATION_PARENT] = ($remoteParent ?? $current?->context())?->spanId ?? '';
            $remoteParent = $this->spanOf($step)->context();
        }

        return $this->tracer->startSpan($name, $kind, $attributes, $remoteParent);
    }

    public function startRootSpan(string $name, SpanKind $kind, array $attributes = []): ActiveSpan
    {
        return $this->tracer->startRootSpan($name, $kind, $this->withExecutionAttributes($attributes));
    }

    public function startDetachedSpan(string $name, SpanKind $kind, array $attributes, ?SpanContext $parent, DatePoint $startedAt): ActiveSpan
    {
        return $this->tracer->startDetachedSpan(name: $name, kind: $kind, attributes: $attributes, parent: $parent, startedAt: $startedAt);
    }

    public function currentSpan(): ?ActiveSpan
    {
        $current = $this->tracer->currentSpan();
        for ($step = $this->openStepOf($current); $step !== null && $step->owner === $current; $step = $step->parentStep) {
            if ($step->span !== null) {
                return $step->span;
            }
        }

        return $current;
    }

    public function currentApplicationSpan(): ?ActiveSpan
    {
        return $this->tracer->currentSpan();
    }

    /**
     * @param list<array{depth: int, name: string, hints?: list<ExecutionHint>}> $notes
     * @param array<string, scalar> $attributes
     */
    public function awaitReceive(string $messageId, string $name, array $notes, array $attributes, ?SpanContext $remoteParent): void
    {
        $this->state()->awaitedReceive = ['messageId' => $messageId, 'name' => $name, 'notes' => $notes, 'attributes' => $attributes, 'remoteParent' => $remoteParent];
    }

    public function startGatewayEntry(string $name, ?string $handledMessageId): ?ExecutionStep
    {
        $this->dropStepsOfEndedSpans();
        if ($this->tracer->currentSpan() !== null || $this->state()->openSteps !== []) {
            return $this->startStep($name);
        }
        $awaitedReceive = $this->state()->awaitedReceive;
        $this->state()->awaitedReceive = null;
        if ($awaitedReceive === null || $handledMessageId === null || $awaitedReceive['messageId'] !== $handledMessageId) {
            return $this->startEntry($name);
        }
        $this->state()->receiveEntry = $this->startEntry(name: $awaitedReceive['name'], attributes: $awaitedReceive['attributes'], remoteParent: $awaitedReceive['remoteParent']);
        $this->recordNested($awaitedReceive['notes']);
        $this->state()->receiveContinuation = $this->startStep($name);

        return $this->state()->receiveContinuation;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    public function startEntry(string $name, array $attributes = [], ?SpanContext $remoteParent = null): ?ExecutionStep
    {
        $this->dropStepsOfEndedSpans();
        if ($this->tracer->currentSpan() !== null || $this->state()->openSteps !== []) {
            return $this->startStep($name, $attributes);
        }
        $this->state()->stepsOfOpenEntry = [];
        $this->state()->spanStartedInOpenEntry = false;
        $this->state()->invocationInOpenEntry = false;
        $this->state()->housekeepingInOpenEntry = false;
        $this->state()->lastCoroutineStartedBeforeTheOpenEntry = ExecutionScope::lastStartedCoroutineId();
        $startedBy = $this->state()->orderOfTheStepThatStartedThisCoroutine;
        $entry = new ExecutionStep(
            name: $name,
            attributes: [
                SpanAttribute::MESSAGING_SYSTEM => SpanAttribute::ECOTONE,
                SpanAttribute::EXECUTION_STEP => true,
                SpanAttribute::EXECUTION_PROCESS => true,
                SpanAttribute::EXECUTION_ORDER => $this->nextOrder(),
                ...($startedBy === null ? [] : [SpanAttribute::EXECUTION_STARTED_IN_A_COROUTINE_OF => $startedBy]),
                ...$attributes,
            ],
            owner: null,
            entry: true,
            parentStep: null,
            parentContext: $remoteParent,
            startedAt: $this->clock->now(),
        );
        $this->state()->openSteps[] = $entry;

        return $entry;
    }

    /**
     * @param array<string, scalar> $attributes
     * @param list<ExecutionHint> $hints
     */
    public function startStep(string $name, array $attributes = [], bool $invocation = false, array $hints = [], bool $shownOnlyWhenSomethingRunsInside = false): ?ExecutionStep
    {
        $current = $this->tracer->currentSpan();
        if ($current === null && $this->state()->openSteps === []) {
            return null;
        }
        if ($invocation) {
            $this->state()->invocationInOpenEntry = true;
        }
        $parentStep = $this->openStepOf($current);
        if ($parentStep === null && $current === null) {
            return null;
        }
        if ($parentStep !== null) {
            $this->state()->stepsWithChildren[spl_object_id($parentStep)] = true;
        }
        $step = new ExecutionStep(
            name: $name,
            attributes: [
                SpanAttribute::MESSAGING_SYSTEM => SpanAttribute::ECOTONE,
                SpanAttribute::EXECUTION_STEP => true,
                SpanAttribute::EXECUTION_ORDER => $this->nextOrder(),
                ...$attributes,
                ...($hints === [] ? [] : [SpanAttribute::EXECUTION_HINTS => ExecutionHint::encoded($hints)]),
            ],
            owner: $current,
            entry: false,
            parentStep: $parentStep,
            parentContext: $parentStep === null ? $current?->context() : null,
            startedAt: $this->clock->now(),
        );
        if (! $shownOnlyWhenSomethingRunsInside && ($parentStep === null || $parentStep->span !== null)) {
            $this->spanOf($step);
        }
        $this->state()->openSteps[] = $step;

        return $step;
    }

    /**
     * @param list<array{depth: int, name: string, hints?: list<ExecutionHint>}> $notes
     */
    private function recordNested(array $notes): void
    {
        $open = [];
        foreach ($notes as $note) {
            while (count($open) > $note['depth']) {
                $this->endStep(array_pop($open), '');
            }
            $step = $this->startStep($note['name'], hints: $note['hints'] ?? []);
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
     * @param list<ExecutionHint> $hints
     */
    public function recordStep(string $name, array $attributes = [], array $hints = []): void
    {
        $step = $this->startStep($name, $attributes, hints: $hints);
        if ($step !== null) {
            $this->endStep($step, '');
        }
    }

    public function hintOpenStep(ExecutionHint $hint): void
    {
        $step = $this->state()->openSteps[count($this->state()->openSteps) - 1] ?? null;
        if ($step !== null) {
            $this->state()->hintsOfOpenSteps[spl_object_id($step)][] = $hint;
        }
    }

    /**
     * @param list<ExecutionHint> $hints
     */
    public function endStep(ExecutionStep $step, string $outcome, array $hints = []): void
    {
        $this->finish(step: $step, outcome: $outcome, failure: null, hints: $hints);
    }

    public function endStepWhenSomethingRanInside(ExecutionStep $step): void
    {
        if (isset($this->state()->stepsWithChildren[spl_object_id($step)])) {
            $this->endStep($step, '');

            return;
        }
        $position = array_search($step, $this->state()->openSteps, true);
        if ($position !== false) {
            array_splice($this->state()->openSteps, $position, 1);
        }
        unset($this->state()->hintsOfOpenSteps[spl_object_id($step)]);
        $this->leaveUnrecorded($step);
    }

    public function failStep(ExecutionStep $step, Throwable $failure): void
    {
        $this->finish(step: $step, outcome: 'throws ' . self::shortClassOf($failure::class), failure: $failure);
    }

    /**
     * @param list<ExecutionHint> $hints
     */
    private static function withHints(string $encoded, array $hints): string
    {
        return ExecutionHint::json([...(array) json_decode($encoded, true), ...(array) json_decode(ExecutionHint::encoded($hints), true)]);
    }

    public static function shortClassOf(string $class): string
    {
        $class = explode("\0", $class, 2)[0];

        return substr($class, (int) strrpos('\\' . $class, '\\'));
    }

    /**
     * @param list<ExecutionHint> $hints
     */
    private function finish(ExecutionStep $step, string $outcome, ?Throwable $failure, array $hints = []): void
    {
        $position = array_search($step, $this->state()->openSteps, true);
        if ($position === false) {
            return;
        }
        array_splice($this->state()->openSteps, $position, 1);
        $key = spl_object_id($step);
        unset($this->state()->stepsWithChildren[$key]);
        $hints = [...($this->state()->hintsOfOpenSteps[$key] ?? []), ...$hints];
        unset($this->state()->hintsOfOpenSteps[$key]);
        if ($this->state()->openSteps === []) {
            $this->state()->stepsWithChildren = [];
            $this->state()->hintsOfOpenSteps = [];
        }
        $step->ending = ['outcome' => $outcome, 'hints' => $hints, 'failure' => $failure];
        if ($step->entry) {
            $this->state()->openSteps = [];
            $this->state()->hintsOfOpenSteps = [];
            $this->state()->stepsWithChildren = [];
            $this->closeEntry($step);

            return;
        }
        $ended = $step->span === null && $this->entryIsNotExportedYet() ? $step : $this->ended($step);
        if ($ended === null) {
            return;
        }
        if ($this->state()->receiveEntry !== null && $step === $this->state()->receiveContinuation) {
            $this->state()->stepsOfOpenEntry[] = $ended;
            $receiveEntry = $this->state()->receiveEntry;
            $this->state()->receiveEntry = null;
            $this->state()->receiveContinuation = null;
            $this->finish(step: $receiveEntry, outcome: '', failure: $failure);

            return;
        }
        if ($this->state()->openSteps !== [] && $this->state()->openSteps[0]->entry) {
            $this->state()->stepsOfOpenEntry[] = $ended;

            return;
        }
        if ($ended instanceof Span) {
            $this->spanRecorder->release($ended);
        }
    }

    private function entryIsNotExportedYet(): bool
    {
        return $this->state()->openSteps !== [] && $this->state()->openSteps[0]->entry && $this->state()->openSteps[0]->span === null;
    }

    private function ended(ExecutionStep $step): ?Span
    {
        $activeSpan = $this->spanOf($step);
        $ending = $step->ending ?? ['outcome' => '', 'hints' => [], 'failure' => null];
        if ($ending['outcome'] !== '') {
            $activeSpan->setAttribute(SpanAttribute::EXECUTION_OUTCOME, $ending['outcome']);
        }
        if ($ending['hints'] !== []) {
            $activeSpan->setAttribute(SpanAttribute::EXECUTION_HINTS, self::withHints((string) ($step->attributes[SpanAttribute::EXECUTION_HINTS] ?? '[]'), $ending['hints']));
        }

        return $this->endedSpanOf(activeSpan: $activeSpan, failure: $ending['failure']);
    }

    private function endedSpanOf(ActiveSpan $activeSpan, ?Throwable $failure): ?Span
    {
        $this->spanRecorder->capture($activeSpan->context()->spanId);
        if ($failure === null) {
            $activeSpan->end();
        } else {
            $activeSpan->fail($failure);
        }

        return $this->spanRecorder->popCaptured($activeSpan->context()->spanId);
    }

    private function leaveUnrecorded(ExecutionStep $step): void
    {
        if ($step->span !== null) {
            $this->endedSpanOf(activeSpan: $step->span, failure: null);
        }
    }

    private function spanOf(ExecutionStep $step): ActiveSpan
    {
        if ($step->span !== null) {
            return $step->span;
        }
        $chain = [];
        for ($pending = $step; $pending !== null && $pending->span === null; $pending = $pending->parentStep) {
            array_unshift($chain, $pending);
        }
        $span = null;
        foreach ($chain as $pending) {
            $parent = $pending->parentStep === null ? $pending->parentContext : $pending->parentStep->span?->context();
            $stableName = (string) preg_replace(RecordedExecution::GENERATED_ID, '{generated}', $pending->name);
            $span = $pending->span = $this->tracer->startDetachedSpan(
                name: $stableName,
                kind: SpanKind::Internal,
                attributes: $stableName === $pending->name ? $pending->attributes : [...$pending->attributes, SpanAttribute::EXECUTION_LABEL => $pending->name],
                parent: $parent,
                startedAt: $pending->startedAt,
            );
        }
        if ($chain[0]->entry) {
            $this->exportStepsEndedBefore();
        }

        return $span;
    }

    private function exportStepsEndedBefore(): void
    {
        foreach ($this->state()->stepsOfOpenEntry as $step) {
            if ($step instanceof ExecutionStep) {
                $this->spanOf($step);
            }
        }
        $exported = [];
        foreach ($this->state()->stepsOfOpenEntry as $step) {
            $span = $step instanceof ExecutionStep ? $this->ended($step) : $step;
            if ($span !== null) {
                $exported[] = $span;
            }
        }
        $this->state()->stepsOfOpenEntry = $exported;
    }

    public function openCollector(string $channelName): void
    {
        $collector = $this->state()->collectors[$channelName] ?? ['depth' => 0, 'collected' => []];
        $collector['depth']++;
        $this->state()->collectors[$channelName] = $collector;
    }

    public function closeCollector(string $channelName): void
    {
        if (--$this->state()->collectors[$channelName]['depth'] === 0) {
            unset($this->state()->collectors[$channelName]);
        }
    }

    public function collect(string $channelName, string $messageId): void
    {
        if (isset($this->state()->collectors[$channelName])) {
            $this->state()->collectors[$channelName]['collected'][$messageId] = true;
        }
    }

    public function releaseCollected(string $channelName, string $messageId): bool
    {
        if (! isset($this->state()->collectors[$channelName]['collected'][$messageId])) {
            return false;
        }
        unset($this->state()->collectors[$channelName]['collected'][$messageId]);

        return true;
    }

    public function suspendOpenEntry(): void
    {
        if ($this->tracer->currentSpan() !== null) {
            $this->state()->suspendedEntries[] = null;

            return;
        }
        $suspended = [];
        foreach (self::STATE_SUSPENDED_WITH_THE_OPEN_ENTRY as $property) {
            $suspended[$property] = $this->state()->{$property};
        }
        $this->state()->suspendedEntries[] = $suspended;
        $this->state()->openSteps = [];
        $this->state()->stepsWithChildren = [];
        $this->state()->stepsOfOpenEntry = [];
        $this->state()->spanStartedInOpenEntry = false;
        $this->state()->receiveEntry = null;
        $this->state()->receiveContinuation = null;
        $this->state()->invocationInOpenEntry = false;
        $this->state()->housekeepingInOpenEntry = false;
        $this->state()->collectors = [];
        $this->state()->hintsOfOpenSteps = [];
    }

    public function resumeSuspendedEntry(): void
    {
        foreach (array_pop($this->state()->suspendedEntries) ?? [] as $property => $value) {
            $this->state()->{$property} = $value;
        }
    }

    public function markHousekeeping(): void
    {
        if (! $this->state()->spanStartedInOpenEntry && ! $this->state()->invocationInOpenEntry) {
            $this->state()->housekeepingInOpenEntry = true;
        }
    }

    public function startFlowTesting(): void
    {
        $this->state()->flowTestingFailures[] = null;
    }

    public function flowTestingFailed(Throwable $failure): void
    {
        if ($this->state()->flowTestingFailures !== []) {
            $this->state()->flowTestingFailures[count($this->state()->flowTestingFailures) - 1] = self::shortClassOf($failure::class);
        }
    }

    public function endFlowTesting(): ?string
    {
        return array_pop($this->state()->flowTestingFailures);
    }

    private function closeEntry(ExecutionStep $entry): void
    {
        $kept = $entry->ending['failure'] !== null || (! $this->state()->housekeepingInOpenEntry && ($this->state()->spanStartedInOpenEntry || $this->state()->invocationInOpenEntry));
        if ($kept) {
            $this->spanOf($entry);
        }
        $steps = $this->state()->stepsOfOpenEntry;
        $this->state()->stepsOfOpenEntry = [];
        $this->state()->spanStartedInOpenEntry = false;
        $this->state()->invocationInOpenEntry = false;
        $this->state()->housekeepingInOpenEntry = false;
        if (! $kept) {
            foreach ([...$steps, $entry] as $step) {
                if ($step instanceof ExecutionStep) {
                    $this->leaveUnrecorded($step);
                }
            }

            return;
        }
        foreach ([...$steps, $entry] as $step) {
            $span = $step instanceof ExecutionStep ? $this->ended($step) : $step;
            if ($span !== null) {
                $this->spanRecorder->release($span);
            }
        }
    }

    private function nextOrder(): int
    {
        return $this->order = max($this->order + 1, hrtime(true));
    }

    private function dropStepsOfEndedSpans(): void
    {
        if ($this->tracer->currentSpan() !== null || $this->state()->openSteps === [] || $this->state()->openSteps[0]->owner === null) {
            return;
        }
        foreach (array_reverse($this->state()->openSteps) as $step) {
            $this->leaveUnrecorded($step);
        }
        $this->state()->openSteps = [];
        $this->state()->stepsWithChildren = [];
        $this->state()->hintsOfOpenSteps = [];
        $this->state()->receiveEntry = null;
        $this->state()->receiveContinuation = null;
    }

    private function openStepOf(?ActiveSpan $current): ?ExecutionStep
    {
        $step = $this->state()->openSteps[count($this->state()->openSteps) - 1] ?? null;

        return $step !== null && $step->owner === $current ? $step : null;
    }

    /**
     * @param array<string, scalar> $attributes
     * @return array<string, scalar>
     */
    private function withExecutionAttributes(array $attributes): array
    {
        $attributes[SpanAttribute::EXECUTION_ORDER] = $this->nextOrder();
        $current = $this->tracer->currentSpan();
        if ($current === null && $this->state()->openSteps === []) {
            $attributes[SpanAttribute::EXECUTION_PROCESS] = true;

            return $attributes;
        }
        if ($current === null) {
            $this->state()->spanStartedInOpenEntry = true;
        }
        $step = $this->openStepOf($current);
        if ($step !== null) {
            $attributes[SpanAttribute::EXECUTION_PARENT] = $this->spanOf($step)->context()->spanId;
        }

        return $attributes;
    }

    private function state(): ExecutionRecordingState
    {
        return ExecutionScope::stateOf($this, static fn () => new ExecutionRecordingState());
    }
}
