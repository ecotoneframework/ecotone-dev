<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Closure;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Tracing\SpanAttribute;
use Ecotone\Api\Tracing\SpanKind;
use Ecotone\Dbal\Pulse\Tracking\PulseProjectionActions;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Modelling\MessageHandling\Distribution\Pulse\PulseRequest;
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

    public function recordFailedAttemptHandedToErrorChannel(MethodInvocation $methodInvocation): mixed
    {
        $this->recorder->beginConsumedMessage($this->tracer->currentSpan());
        try {
            $result = $methodInvocation->proceed();
            $this->storeFailedAttempt();

            return $result;
        } finally {
            $this->recorder->endConsumedMessage();
        }
    }

    public function keepPulseRequestOutOfFlowTracking(MethodInvocation $methodInvocation, Message $message): mixed
    {
        $headers = $message->getHeaders();
        if ($headers->containsKey(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY) && PulseRequest::isRoutedToPulse($headers->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY)) && ! in_array($headers->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY), PulseProjectionActions::TRACKED_ROUTING_KEYS, true)) {
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
            $this->recorder->forgetFailedAttemptOfOpenScope();

            return $result;
        } catch (Throwable $failure) {
            $this->recorder->holdFailedAttempt($failure);

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
                    $this->recorder->flushWithOpenSpansWithinOpenScope($this->tracer->currentSpan());
                } else {
                    $this->recorder->flushBufferedWithinOpenScope();
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

    private function storeFailedAttempt(): void
    {
        try {
            $this->recorder->storeFailedAttempt();
        } catch (Throwable $failure) {
            $this->logger->error(sprintf('The failed step of a consumed message was not recorded in flow tracking, because storing it failed: %s. The message itself was already handed to the error channel, so only its trace misses the failure; check the connection flow tracking stores spans on.', $failure->getMessage()), [], ['exception' => $failure]);
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
