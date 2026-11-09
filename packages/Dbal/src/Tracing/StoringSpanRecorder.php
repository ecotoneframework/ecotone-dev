<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\SpanStatus;
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
    /** @var Span[] */
    private array $buffer = [];
    private bool $scopeActive = false;
    private bool $scopeExcludedFromTracking = false;
    private ?ActiveSpan $wrappingSpan = null;
    /** @var array<string, true> */
    private array $storedSpanIds = [];
    private bool $projectionBatchActive = false;
    private bool $unitOfWorkActive = false;
    /** @var Span[] */
    private array $committedSpans = [];
    /** @var Span[] */
    private array $failedAttempt = [];

    public function __construct(
        private TraceStore $traceStore,
        private bool $publishesEndedSpans,
        private bool $storesOnlyCarriedTraces,
    ) {
    }

    public function record(Span $span): void
    {
        if ($this->scopeActive && ! $this->scopeExcludedFromTracking && $this->isStored($span)) {
            $this->buffer[] = $span;
        }
    }

    public function isUnitOfWorkActive(): bool
    {
        return $this->unitOfWorkActive;
    }

    public function beginUnitOfWork(): void
    {
        $this->unitOfWorkActive = true;
    }

    public function endUnitOfWork(): void
    {
        $this->unitOfWorkActive = false;
        $this->committedSpans = [];
    }

    public function openScope(?ActiveSpan $wrappingSpan): void
    {
        $this->scopeActive = true;
        $this->wrappingSpan = $wrappingSpan;
    }

    public function isScopeActive(): bool
    {
        return $this->scopeActive;
    }

    public function excludeScopeFromTracking(): void
    {
        if (! $this->scopeActive) {
            return;
        }

        $this->scopeExcludedFromTracking = true;
        $this->buffer = [];
    }

    public function closeScope(): void
    {
        $this->scopeActive = false;
        $this->scopeExcludedFromTracking = false;
        $this->wrappingSpan = null;
        $this->storedSpanIds = [];
    }

    public function beginProjectionBatch(): void
    {
        $this->projectionBatchActive = true;
    }

    public function isProjectionBatchActive(): bool
    {
        return $this->projectionBatchActive;
    }

    public function endProjectionBatch(): void
    {
        $this->projectionBatchActive = false;
    }

    public function withinTransaction(Closure $work): mixed
    {
        return $this->traceStore->withinTransaction($work);
    }

    public function flushBufferedWithinOpenScope(): void
    {
        $this->store([], $this->wrappingSpan?->context()->spanId);
    }

    public function flushWithOpenSpansWithinOpenScope(?ActiveSpan ...$innerOpenSpans): void
    {
        $this->store($this->openSpansSoFar($innerOpenSpans), $this->wrappingSpan?->context()->spanId);
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
        foreach ([$this->wrappingSpan, ...$innerOpenSpans] as $openSpan) {
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
        if ($this->scopeExcludedFromTracking) {
            $this->buffer = [];

            return;
        }

        $spans = [];
        foreach ([...$this->buffer, ...$openSpansSoFar] as $span) {
            if ($this->isStored($span) && ! isset($this->storedSpanIds[$span->spanId])) {
                $spans[$span->spanId] = $span;
            }
        }
        $this->buffer = [];
        if ($spans === []) {
            return;
        }

        $spans = array_values($spans);
        $this->traceStore->append($spans, $onlyPossibleEntrySpanId);
        foreach ($spans as $span) {
            $this->storedSpanIds[$span->spanId] = true;
        }
        $this->committedSpans = [...$this->committedSpans, ...$spans];
    }

    public function discard(): void
    {
        $this->buffer = [];
    }

    public function holdFailedAttempt(Throwable $failure): void
    {
        $failedSteps = array_filter($this->buffer, fn (Span $span): bool => $span->status === SpanStatus::Error);
        $this->buffer = [];
        $this->failedAttempt = [];
        if ($this->scopeExcludedFromTracking || $this->wrappingSpan === null) {
            return;
        }
        $wrapping = $this->wrappingSpan->endedSoFar();
        if (! $this->isStored($wrapping)) {
            return;
        }
        $wrapping = new Span($wrapping->traceId, $wrapping->spanId, $wrapping->parentSpanId, $wrapping->name, $wrapping->kind, $wrapping->attributes, SpanStatus::Error, $failure->getMessage(), $wrapping->events, $wrapping->startedAt, $wrapping->endedAt);
        foreach ([$wrapping, ...$failedSteps] as $span) {
            $this->failedAttempt[$span->spanId] = new Span($span->traceId, $span->spanId, $span->parentSpanId, $span->name, $span->kind, [...$span->attributes, TraceLedger::FAILED_ATTEMPT => true], $span->status, $span->statusDescription, $span->events, $span->startedAt, $span->endedAt);
        }
    }

    public function forgetFailedAttempt(): void
    {
        $this->failedAttempt = [];
    }

    public function storeFailedAttempt(): void
    {
        $spans = array_values($this->failedAttempt);
        $this->failedAttempt = [];
        if ($spans !== []) {
            $this->traceStore->append($spans, $spans[0]->spanId);
        }
    }

    /**
     * @return FlowStepCompleted[]
     */
    public function popCommittedNotifications(): array
    {
        $notifications = array_map(fn (Span $span): FlowStepCompleted => new FlowStepCompleted($span), $this->publishesEndedSpans ? $this->committedSpans : []);
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
}
