<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanEnded;
use Ecotone\Api\Tracing\SpanRecorder;
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
    private bool $unitOfWorkActive = false;
    private bool $publishing = false;
    /** @var Span[] */
    private array $committedSpans = [];
    /** @var string[] */
    private array $completedTraces = [];

    public function __construct(
        private TraceStore $traceStore,
        private MessagingEntrypointService $messagingEntrypoint,
        private bool $publishesEndedSpans,
        private bool $publishesCompletedTraces,
        private bool $storesOnlyCarriedTraces,
        private EcotoneClockInterface $clock,
    ) {
    }

    public function record(Span $span): void
    {
        if ($this->scopeActive && $this->isStored($span)) {
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
        $this->completedTraces = [];
    }

    public function openScope(): void
    {
        $this->scopeActive = true;
    }

    public function isScopeActive(): bool
    {
        return $this->scopeActive;
    }

    public function closeScope(): void
    {
        $this->scopeActive = false;
    }

    public function flush(?Span $wrappingSpanSoFar): void
    {
        $spans = $this->buffer;
        $this->buffer = [];
        if ($wrappingSpanSoFar !== null && $this->isStored($wrappingSpanSoFar)) {
            $spans[] = $wrappingSpanSoFar;
        }
        if ($spans === []) {
            return;
        }

        $this->traceStore->append($spans);
        $completedTraces = [];
        foreach (array_unique(array_map(fn (Span $span): string => $span->traceId, $spans)) as $traceId) {
            if ($this->traceStore->markCompletedIfDone($traceId, $this->clock->now())) {
                $completedTraces[] = $traceId;
            }
        }
        if ($this->publishing) {
            return;
        }
        $this->committedSpans = [...$this->committedSpans, ...$spans];
        $this->completedTraces = [...$this->completedTraces, ...$completedTraces];
    }

    public function discard(): void
    {
        $this->buffer = [];
    }

    public function publishCommitted(): void
    {
        $endedSpans = $this->publishesEndedSpans ? $this->committedSpans : [];
        $completedTraces = $this->publishesCompletedTraces ? $this->completedTraces : [];
        $this->endUnitOfWork();

        $this->publishing = true;
        try {
            foreach ($endedSpans as $span) {
                $this->deliver(new SpanEnded($span), OnSpanEnded::CHANNEL);
            }
            foreach (array_unique($completedTraces) as $traceId) {
                $this->deliver(new TraceCompleted($traceId), OnTraceCompleted::CHANNEL);
            }
        } finally {
            $this->publishing = false;
        }
    }

    private function isStored(Span $span): bool
    {
        return ! $this->storesOnlyCarriedTraces || ($span->attributes[SpanAttribute::TRACE_CARRIED] ?? false);
    }

    private function deliver(object $notification, string $channel): void
    {
        try {
            $this->messagingEntrypoint->send($notification, $channel);
        } catch (Throwable) {
        }
    }
}
