<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanEnded;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Api\Tracing\TraceCompleted;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
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
    private const NOTIFICATION_RETRY_DELAY_IN_SECONDS = 30;
    private const NOTIFICATION_RETRY_BATCH = 10;

    /** @var Span[] */
    private array $buffer = [];
    /** @var Span[] */
    private array $flushedAwaitingCommit = [];
    private bool $scopeActive = false;
    private bool $publishing = false;

    public function __construct(
        private TraceStore $traceStore,
        private MessagingEntrypointService $messagingEntrypoint,
        private bool $publishesEndedSpans,
        private bool $publishesCompletedTraces,
        private bool $storesFailuresOutsideTransaction,
        private bool $storesOnlyCarriedTraces,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function record(Span $span): void
    {
        if ($this->storesOnlyCarriedTraces && ! ($span->attributes[SpanAttribute::TRACE_CARRIED] ?? false)) {
            return;
        }
        if ($this->scopeActive) {
            $this->buffer[] = $span;

            return;
        }
        $spans = [...$this->buffer, $span];
        $flushed = $this->flushedAwaitingCommit;
        $this->buffer = [];
        $this->flushedAwaitingCommit = [];
        if ($span->status === SpanStatus::Error && ! $this->storesFailuresOutsideTransaction) {
            return;
        }
        $this->write($spans, $flushed);
    }

    public function openScope(): void
    {
        $this->scopeActive = true;
    }

    public function isScopeActive(): bool
    {
        return $this->scopeActive;
    }

    public function flush(): void
    {
        $spans = $this->buffer;
        $this->buffer = [];
        if ($spans === []) {
            return;
        }
        $this->traceStore->append($spans);
        $this->flushedAwaitingCommit = [...$this->flushedAwaitingCommit, ...$spans];
    }

    public function closeScope(): void
    {
        $this->scopeActive = false;
    }

    public function discard(): void
    {
        if (! $this->storesFailuresOutsideTransaction) {
            $this->buffer = [];
            $this->flushedAwaitingCommit = [];
        }
    }

    /**
     * @param Span[] $spans
     * @param Span[] $flushedBefore
     */
    private function write(array $spans, array $flushedBefore): void
    {
        $this->traceStore->append($spans);
        $completedTraces = $this->completedAmong([...$flushedBefore, ...$spans]);
        if ($this->publishing) {
            return;
        }
        $this->publish($this->publishesEndedSpans ? [...$flushedBefore, ...$spans] : [], $this->publishesCompletedTraces ? $completedTraces : []);
    }

    /**
     * @param Span[] $spans
     * @return string[]
     */
    private function completedAmong(array $spans): array
    {
        $completed = [];
        foreach (array_unique(array_map(fn (Span $span): string => $span->traceId, $spans)) as $traceId) {
            if ($this->traceStore->markCompletedIfDone($traceId, $this->clock->now())) {
                $completed[] = $traceId;
            }
        }

        return $completed;
    }

    /**
     * @param Span[] $endedSpans
     * @param string[] $completedTraces
     */
    private function publish(array $endedSpans, array $completedTraces): void
    {
        $this->publishing = true;
        try {
            foreach ($endedSpans as $span) {
                $this->messagingEntrypoint->send(new SpanEnded($span), OnSpanEnded::CHANNEL);
            }
            if ($this->publishesCompletedTraces) {
                $completedTraces = [...$completedTraces, ...$this->traceStore->completedButNotNotifiedBefore($this->clock->now()->sub(Duration::seconds(self::NOTIFICATION_RETRY_DELAY_IN_SECONDS)), self::NOTIFICATION_RETRY_BATCH)];
            }
            foreach (array_unique($completedTraces) as $traceId) {
                $this->notify($traceId);
            }
        } finally {
            $this->publishing = false;
        }
    }

    private function notify(string $traceId): void
    {
        try {
            $this->messagingEntrypoint->send(new TraceCompleted($traceId), OnTraceCompleted::CHANNEL);
        } catch (Throwable) {
            return;
        }
        $this->traceStore->markNotified($traceId);
    }
}
