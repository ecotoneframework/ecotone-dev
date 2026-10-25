<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Tracing\ActiveSpan;

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

    public function flushBuffered(): void
    {
        $this->store([]);
    }

    public function flushWithOpenSpans(?ActiveSpan ...$innerOpenSpans): void
    {
        $openSpans = [];
        foreach ([$this->wrappingSpan, ...$innerOpenSpans] as $openSpan) {
            if ($openSpan !== null) {
                $openSpans[$openSpan->context()->spanId] = $openSpan->endedSoFar();
            }
        }
        $this->store(array_values($openSpans));
    }

    /**
     * @param Span[] $openSpansSoFar
     */
    private function store(array $openSpansSoFar): void
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
        $this->traceStore->append($spans);
        foreach ($spans as $span) {
            $this->storedSpanIds[$span->spanId] = true;
        }
        $this->committedSpans = [...$this->committedSpans, ...$spans];
    }

    public function discard(): void
    {
        $this->buffer = [];
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
