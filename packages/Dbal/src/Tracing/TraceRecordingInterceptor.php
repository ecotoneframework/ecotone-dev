<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Tracing;

use Ecotone\Api\Dbal\Tracing\FlowCompleted;
use Ecotone\Api\Dbal\Tracing\FlowStepCompleted;
use Ecotone\Api\Dbal\Tracing\OnFlowCompleted;
use Ecotone\Api\Dbal\Tracing\OnFlowStepCompleted;
use Ecotone\Api\Interceptor\MethodInvocation;
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
final class TraceRecordingInterceptor
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
        if ($this->recorder->isUnitOfWorkActive()) {
            return $methodInvocation->proceed();
        }

        $this->recorder->beginUnitOfWork();
        try {
            $result = $methodInvocation->proceed();
        } catch (Throwable $failure) {
            $this->recorder->endUnitOfWork();

            throw $failure;
        }
        foreach ($this->recorder->popCommittedNotifications() as $notification) {
            $this->deliver($notification);
        }

        return $result;
    }

    public function recordUnitOfWork(MethodInvocation $methodInvocation): mixed
    {
        if ($this->recorder->isScopeActive()) {
            return $methodInvocation->proceed();
        }

        $wrappingSpan = $this->tracer->currentSpan();
        $this->recorder->openScope();
        try {
            $result = $methodInvocation->proceed();
            $this->recorder->flush($wrappingSpan?->endedSoFar());

            return $result;
        } catch (Throwable $failure) {
            $this->recorder->discard();

            throw $failure;
        } finally {
            $this->recorder->closeScope();
        }
    }

    private function deliver(FlowStepCompleted|FlowCompleted $notification): void
    {
        $channel = $notification instanceof FlowCompleted ? OnFlowCompleted::CHANNEL : OnFlowStepCompleted::CHANNEL;
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
