<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Dbal\Pulse\Tracking\PulseRebuildActions;
use Closure;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Tracing\Tracer;
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
final class TraceRecordingInterceptor implements FlowTrackingScope
{
    private const PULSE_ROUTING_PREFIX = 'ecotone.pulse.';

    public function __construct(
        private StoringSpanRecorder $recorder,
        private Tracer $tracer,
        private MessagingEntrypointService $messagingEntrypoint,
        private LoggingGateway $logger,
    ) {
    }

    public function publishAfterCommit(MethodInvocation $methodInvocation): mixed
    {
        return $this->publishingStepsAfterCommit(fn (): mixed => $methodInvocation->proceed());
    }

    public function recordUnitOfWork(MethodInvocation $methodInvocation): mixed
    {
        return $this->storingSpansOf(fn (): mixed => $methodInvocation->proceed());
    }

    public function keepPulseRequestOutOfFlowTracking(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $headers = $message->getHeaders();
        if ($headers->containsKey(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY) && str_starts_with((string) $headers->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY), self::PULSE_ROUTING_PREFIX) && $headers->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY) !== PulseRebuildActions::ROUTING_KEY) {
            $this->recorder->excludeScopeFromTracking();
        }

        return $methodInvocation->proceed();
    }

    public function publishingStepsAfterCommit(Closure $work): mixed
    {
        if ($this->recorder->isUnitOfWorkActive()) {
            return $work();
        }

        $this->recorder->beginUnitOfWork();
        try {
            $result = $work();
        } catch (Throwable $failure) {
            $this->recorder->endUnitOfWork();

            throw $failure;
        }
        foreach ($this->recorder->popCommittedNotifications() as $notification) {
            $this->deliver($notification);
        }

        return $result;
    }

    public function storingSpansOf(Closure $work): mixed
    {
        if ($this->recorder->isScopeActive()) {
            return $work();
        }

        $this->recorder->openScope($this->tracer->currentSpan());
        try {
            $result = $work();
            $this->recorder->flushWithOpenSpans();

            return $result;
        } catch (Throwable $failure) {
            $this->recorder->discard();

            throw $failure;
        } finally {
            $this->recorder->closeScope();
        }
    }

    public function recordProjectionBatch(MethodInvocation $methodInvocation): mixed
    {
        if (! $this->recorder->isScopeActive() || $this->recorder->isProjectionBatchActive()) {
            return $methodInvocation->proceed();
        }

        $this->recorder->beginProjectionBatch();
        try {
            return $this->recorder->withinTransaction(function () use ($methodInvocation): mixed {
                $processedEvents = $methodInvocation->proceed();
                if ($processedEvents === 0) {
                    $this->recorder->flushWithOpenSpans($this->tracer->currentSpan());
                } else {
                    $this->recorder->flushBuffered();
                }

                return $processedEvents;
            });
        } catch (Throwable $failure) {
            $this->recorder->discard();

            throw $failure;
        } finally {
            $this->recorder->endProjectionBatch();
        }
    }

    private function deliver(FlowStepCompleted $notification): void
    {
        $channel = OnFlowStepCompleted::CHANNEL;
        $span = $this->tracer->startRootSpan('send ' . $channel, SpanKind::Producer, [
            SpanAttribute::MESSAGING_OPERATION_TYPE => SpanAttribute::OPERATION_SEND,
            SpanAttribute::MESSAGING_DESTINATION_NAME => $channel,
            SpanAttribute::TRACE_UNTRACKED => true,
        ]);
        try {
            $this->messagingEntrypoint->send($notification, $channel);
            $span->end();
        } catch (Throwable $failure) {
            $span->fail($failure);
            $this->logger->error(sprintf('A subscriber of %s failed and was skipped: %s', $channel, $failure->getMessage()), [], ['exception' => $failure]);
        }
    }
}
