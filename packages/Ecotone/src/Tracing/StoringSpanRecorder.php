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
    /** @var Span[] */
    private array $buffer = [];
    private bool $scopeActive = false;
    /** @var Span[] */
    private array $endedSpansToPublish = [];
    /** @var string[] */
    private array $completedTracesToPublish = [];
    private const NOTIFICATION_RETRY_DELAY_IN_SECONDS = 30;
    private const NOTIFICATION_RETRY_BATCH = 10;
    private bool $publishing = false;
    /** @var array<string, true> */
    private array $spansWrappingAFailedUnitOfWork = [];

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
        if (isset($this->spansWrappingAFailedUnitOfWork[$span->spanId])) {
            unset($this->spansWrappingAFailedUnitOfWork[$span->spanId]);
            $span = self::failedByItsUnitOfWork($span);
        }
        if ($span->status === SpanStatus::Error && ! $this->storesFailuresOutsideTransaction) {
            return;
        }

        $spans = [...$this->buffer, $span];
        $this->buffer = [];
        $this->write($spans, true);
        $this->publish();
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
        foreach (self::outerParentsOf($this->buffer) as $spanId) {
            unset($this->spansWrappingAFailedUnitOfWork[$spanId]);
        }
        $spans = $this->buffer;
        $this->buffer = [];
        $this->write($spans, false);
    }

    public function closeScope(): void
    {
        $this->scopeActive = false;
    }

    public function discard(): void
    {
        foreach (self::outerParentsOf($this->buffer) as $spanId) {
            $this->spansWrappingAFailedUnitOfWork[$spanId] = true;
        }
        if (! $this->storesFailuresOutsideTransaction) {
            $this->buffer = [];
        }
    }

    /**
     * @param Span[] $spans
     * @return string[]
     */
    private static function outerParentsOf(array $spans): array
    {
        $spanIds = [];
        foreach ($spans as $span) {
            $spanIds[$span->spanId] = true;
        }
        $parents = [];
        foreach ($spans as $span) {
            if ($span->parentSpanId !== null && ! isset($spanIds[$span->parentSpanId])) {
                $parents[$span->parentSpanId] = true;
            }
        }

        return array_keys($parents);
    }

    private static function failedByItsUnitOfWork(Span $span): Span
    {
        if ($span->status === SpanStatus::Error) {
            return $span;
        }

        return new Span(
            $span->traceId,
            $span->spanId,
            $span->parentSpanId,
            $span->name,
            $span->kind,
            $span->attributes,
            SpanStatus::Error,
            'The unit of work this span wraps failed and its failure was handled by the error channel or the acknowledgement strategy',
            $span->events,
            $span->startedAt,
            $span->endedAt,
        );
    }

    /**
     * @param Span[] $spans
     */
    private function write(array $spans, bool $decidesCompletion): void
    {
        if ($spans === []) {
            return;
        }

        $this->traceStore->append($spans);
        $completedTraces = $decidesCompletion ? $this->completedAmong($spans) : [];
        if ($this->publishing) {
            return;
        }
        if ($this->publishesEndedSpans) {
            $this->endedSpansToPublish = [...$this->endedSpansToPublish, ...$spans];
        }
        if ($this->publishesCompletedTraces) {
            $this->completedTracesToPublish = [...$this->completedTracesToPublish, ...$completedTraces];
        }
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

    private function publish(): void
    {
        if ($this->publishing) {
            return;
        }
        $endedSpans = $this->endedSpansToPublish;
        $completedTraces = $this->completedTracesToPublish;
        $this->endedSpansToPublish = [];
        $this->completedTracesToPublish = [];

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
