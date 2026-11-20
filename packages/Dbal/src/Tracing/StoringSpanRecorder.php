<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Messaging\Execution\ExecutionScope;
use Ecotone\Tracing\ActiveSpan;
use Throwable;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class StoringSpanRecorder implements SpanRecorder
{
    public function __construct(
        private TraceStore $traceStore,
        private bool $publishesEndedSpans,
        private bool $storesOnlyCarriedTraces,
    ) {
    }

    public function record(Span $span): void
    {
        if ($this->recorded()->scopeActive && ! $this->recorded()->scopeExcludedFromTracking && $this->isStored($span)) {
            $this->recorded()->buffer[] = $span;
        } elseif (! $this->recorded()->scopeActive && $this->recorded()->failedAttempt !== null && $span->traceId === $this->recorded()->failedAttempt['spans'][0]->traceId && $this->isStored($span)) {
            $this->recorded()->failedAttempt['spans'][] = $span;
        }
    }

    public function isUnitOfWorkActive(): bool
    {
        return $this->recorded()->unitOfWorkActive;
    }

    public function beginUnitOfWork(): void
    {
        $this->recorded()->unitOfWorkActive = true;
    }

    public function endUnitOfWork(): void
    {
        $this->recorded()->unitOfWorkActive = false;
        $this->recorded()->committedSpans = [];
    }

    public function openScope(?ActiveSpan $wrappingSpan): void
    {
        $this->recorded()->scopeActive = true;
        $this->recorded()->wrappingSpan = $wrappingSpan;
    }

    public function isScopeActive(): bool
    {
        return $this->recorded()->scopeActive;
    }

    public function excludeScopeFromTracking(): void
    {
        if (! $this->recorded()->scopeActive) {
            return;
        }

        $this->recorded()->scopeExcludedFromTracking = true;
        $this->recorded()->buffer = [];
    }

    public function closeScope(): void
    {
        $this->recorded()->scopeActive = false;
        $this->recorded()->scopeExcludedFromTracking = false;
        $this->recorded()->wrappingSpan = null;
        $this->recorded()->storedSpanIds = [];
    }

    public function beginProjectionBatch(): void
    {
        $this->recorded()->projectionBatchActive = true;
    }

    public function isProjectionBatchActive(): bool
    {
        return $this->recorded()->projectionBatchActive;
    }

    public function endProjectionBatch(): void
    {
        $this->recorded()->projectionBatchActive = false;
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $this->traceStore->withinTransaction($work);
    }

    public function flushBufferedWithinOpenScope(): void
    {
        $this->store([], $this->recorded()->wrappingSpan?->context()->spanId);
    }

    public function flushWithOpenSpansWithinOpenScope(?ActiveSpan ...$innerOpenSpans): void
    {
        $this->store($this->openSpansSoFar($innerOpenSpans), $this->recorded()->wrappingSpan?->context()->spanId);
    }

    public function flushWithOpenSpans(): void
    {
        $this->store($this->openSpansSoFar([]), null);
    }

    /**
     * @param array<?ActiveSpan> $innerOpenSpans
     * @return Span[]
     */
    private function openSpansSoFar(array $innerOpenSpans): array
    {
        $openSpans = [];
        foreach ([$this->recorded()->wrappingSpan, ...$innerOpenSpans] as $openSpan) {
            if ($openSpan !== null) {
                $openSpans[$openSpan->context()->spanId] = $openSpan->endedSoFar();
            }
        }

        return array_values($openSpans);
    }

    /**
     * @param Span[] $openSpansSoFar
     */
    private function store(array $openSpansSoFar, ?string $onlyPossibleEntrySpanId): void
    {
        if ($this->recorded()->scopeExcludedFromTracking) {
            $this->recorded()->buffer = [];

            return;
        }

        $spans = [];
        foreach ([...$this->recorded()->buffer, ...$openSpansSoFar] as $span) {
            if ($this->isStored($span) && ! isset($this->recorded()->storedSpanIds[$span->spanId])) {
                $spans[$span->spanId] = $span;
            }
        }
        $this->recorded()->buffer = [];
        if ($spans === []) {
            return;
        }

        $spans = array_values($spans);
        $this->traceStore->append($spans, $onlyPossibleEntrySpanId);
        foreach ($spans as $span) {
            $this->recorded()->storedSpanIds[$span->spanId] = true;
        }
        $this->recorded()->committedSpans = [...$this->recorded()->committedSpans, ...$spans];
    }

    public function discard(): void
    {
        $this->recorded()->buffer = [];
    }

    public function beginConsumedMessage(?ActiveSpan $consumedMessageSpan): void
    {
        $this->recorded()->consumedMessageSpanId = $consumedMessageSpan?->context()->spanId;
        $this->recorded()->failedAttempt = null;
    }

    public function endConsumedMessage(): void
    {
        $this->recorded()->consumedMessageSpanId = null;
        $this->recorded()->failedAttempt = null;
    }

    public function holdFailedAttempt(Throwable $failure): void
    {
        $buffered = $this->recorded()->buffer;
        $this->recorded()->buffer = [];
        if ($this->recorded()->scopeExcludedFromTracking || $this->recorded()->wrappingSpan === null || $this->recorded()->wrappingSpan->context()->spanId !== $this->recorded()->consumedMessageSpanId) {
            return;
        }
        $wrapping = $this->recorded()->wrappingSpan->endedSoFar();
        if (! $this->isStored($wrapping)) {
            return;
        }
        $wrapping = new Span(...[...get_object_vars($wrapping), 'status' => SpanStatus::Error, 'statusDescription' => $failure->getMessage()]);
        $spans = [];
        foreach ([$wrapping, ...self::failedStepsWithTheirAncestors($buffered)] as $span) {
            $spans[$span->spanId] = new Span(...[...get_object_vars($span), 'attributes' => [...$span->attributes, SpanAttribute::TRACE_FAILED_ATTEMPT => true]]);
        }
        $this->recorded()->failedAttempt = ['wrappingSpanId' => $wrapping->spanId, 'spans' => array_values($spans), 'append' => $this->traceStore->appendingOnCurrentConnection()];
    }

    public function forgetFailedAttemptOfOpenScope(): void
    {
        if ($this->recorded()->failedAttempt !== null && $this->recorded()->failedAttempt['wrappingSpanId'] === $this->recorded()->wrappingSpan?->context()->spanId) {
            $this->recorded()->failedAttempt = null;
        }
    }

    public function storeFailedAttempt(): void
    {
        $failedAttempt = $this->recorded()->failedAttempt;
        $this->recorded()->failedAttempt = null;
        if ($failedAttempt !== null && $failedAttempt['wrappingSpanId'] === $this->recorded()->consumedMessageSpanId) {
            ($failedAttempt['append'])($failedAttempt['spans'], $failedAttempt['wrappingSpanId']);
        }
    }

    /**
     * @param Span[] $buffered
     * @return Span[]
     */
    private static function failedStepsWithTheirAncestors(array $buffered): array
    {
        $byId = [];
        foreach ($buffered as $span) {
            $byId[$span->spanId] = $span;
        }
        $kept = [];
        foreach ($buffered as $span) {
            if ($span->status !== SpanStatus::Error) {
                continue;
            }
            for ($step = $span; $step !== null && ! isset($kept[$step->spanId]); $step = $byId[$step->parentSpanId ?? ''] ?? null) {
                $kept[$step->spanId] = $step;
            }
        }

        return array_values(array_filter($byId, fn (Span $span): bool => isset($kept[$span->spanId])));
    }

    /**
     * @return FlowStepCompleted[]
     */
    public function popCommittedNotifications(): array
    {
        $notifications = array_map(fn (Span $span): FlowStepCompleted => new FlowStepCompleted($span), $this->publishesEndedSpans ? $this->recorded()->committedSpans : []);
        $this->endUnitOfWork();

        return $notifications;
    }

    private function isStored(Span $span): bool
    {
        if ($span->attributes[SpanAttribute::TRACE_UNTRACKED] ?? false) {
            return false;
        }

        return ! $this->storesOnlyCarriedTraces || ($span->attributes[SpanAttribute::TRACE_CARRIED] ?? false);
    }

    private function recorded(): RecordedSpans
    {
        return ExecutionScope::stateOf($this, static fn () => new RecordedSpans());
    }
}
