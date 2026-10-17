<?php

declare(strict_types=1);

namespace Ecotone\Tracing;

use Ecotone\Api\Tracing\OnSpanEnded;
use Ecotone\Api\Tracing\OnTraceCompleted;
use Ecotone\Api\Tracing\Span;
use Ecotone\Api\Tracing\SpanEnded;
use Ecotone\Api\Tracing\SpanRecorder;
use Ecotone\Api\Tracing\SpanStatus;
use Ecotone\Api\Tracing\TraceCompleted;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;

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
    private bool $publishing = false;

    public function __construct(
        private TraceStore $traceStore,
        private MessagingEntrypointService $messagingEntrypoint,
        private bool $publishesEndedSpans,
        private bool $publishesCompletedTraces,
        private bool $storesFailuresOutsideTransaction,
    ) {
    }

    public function record(Span $span): void
    {
        if ($this->scopeActive) {
            $this->buffer[] = $span;

            return;
        }
        if ($span->status === SpanStatus::Error && ! $this->storesFailuresOutsideTransaction) {
            return;
        }

        $this->write([...$this->buffer, $span]);
        $this->buffer = [];
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
        $this->write($this->buffer);
        $this->buffer = [];
    }

    public function closeScope(): void
    {
        $this->scopeActive = false;
    }

    public function discard(): void
    {
        $this->buffer = [];
    }

    /**
     * @param Span[] $spans
     */
    private function write(array $spans): void
    {
        if ($spans === []) {
            return;
        }

        $completedTraces = $this->traceStore->record($spans);
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
            foreach ($completedTraces as $traceId) {
                $this->messagingEntrypoint->send(new TraceCompleted($traceId), OnTraceCompleted::CHANNEL);
            }
        } finally {
            $this->publishing = false;
        }
    }
}
