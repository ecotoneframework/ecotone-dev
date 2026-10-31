<?php

namespace Ecotone\Dbal\Deduplication;

use Ecotone\Api\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\WithoutDatabaseTransaction;
use Ecotone\Api\Gateway\DistributedBusHeader;
use Ecotone\Api\Gateway\EcotoneClockInterface;
use Ecotone\Api\Interceptor\MethodInvocation;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Api\Scheduling\Duration;
use Ecotone\Dbal\MultiTenant\Pulse\PulseSnapshotRequest;
use Ecotone\Messaging\Attribute\IdentifiedAnnotation;
use Ecotone\Messaging\Handler\ClosureExpression\AttributeExpressionExecutor;
use Ecotone\Messaging\Handler\ClosureExpression\ExecutorFor;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Interop\Queue\Exception\Exception;

/**
 * licence Apache-2.0
 */
final class DeduplicationInterceptor
{
    private Duration $minimumTimeToRemoveMessage;

    public function __construct(
        private HandledMessages $handledMessages,
        private EcotoneClockInterface $clock,
        int $minimumTimeToRemoveMessageInMilliseconds,
        private LoggingGateway $logger,
    ) {
        $this->minimumTimeToRemoveMessage = Duration::milliseconds($minimumTimeToRemoveMessageInMilliseconds);
        if ($this->minimumTimeToRemoveMessage->isNegativeOrZero()) {
            throw new Exception('Minimum time to remove message must be greater than 0');
        }
    }

    public function deduplicate(MethodInvocation $methodInvocation, Message $message, #[ExecutorFor(Deduplicated::class)] ?AttributeExpressionExecutor $deduplicated, ?IdentifiedAnnotation $identifiedAnnotation, ?AsynchronousRunningEndpoint $asynchronousRunningEndpoint, ?WithoutDatabaseTransaction $withoutDatabaseTransaction = null): mixed
    {
        if ($withoutDatabaseTransaction !== null || PulseSnapshotRequest::isIn($message)) {
            return $methodInvocation->proceed();
        }

        $key = new DeduplicationKey(
            $this->extractDeduplicationId($message, $deduplicated),
            $this->determineConsumerEndpointId($deduplicated, $asynchronousRunningEndpoint),
            $this->determineRoutingSlip($message, $deduplicated, $identifiedAnnotation),
        );

        if ($this->handledMessages->wasHandled($key)) {
            $this->logger->info('Message with was already handled. Skipping.', $this->logContextOf($key));

            return null;
        }

        if ($this->handledMessages->recordsBeforeHandling()) {
            $this->handledMessages->recordHandled($key, $this->clock->now()->unixTime()->inMilliseconds());
            $result = $methodInvocation->proceed();
        } else {
            $result = $methodInvocation->proceed();
            $this->handledMessages->recordHandled($key, $this->clock->now()->unixTime()->inMilliseconds());
        }
        $this->logger->info('Message was stored in deduplication table.', $this->logContextOf($key));

        return $result;
    }

    public function removeExpiredMessages(): void
    {
        $this->handledMessages->removeHandledBefore($this->clock->now()->sub($this->minimumTimeToRemoveMessage)->unixTime()->inMilliseconds());
    }

    /**
     * @return array{message_id: string, consumer_endpoint_id: string, routing_slip: string}
     */
    private function logContextOf(DeduplicationKey $key): array
    {
        return [
            'message_id' => $key->messageId,
            'consumer_endpoint_id' => $key->consumerEndpointId,
            'routing_slip' => $key->routingSlip,
        ];
    }

    private function extractDeduplicationId(Message $message, ?AttributeExpressionExecutor $deduplicated): string
    {
        if ($deduplicated === null) {
            return $this->isDistributedInboundWithIdempotencyKey($message)
                ? $message->getHeaders()->get(DistributedBusHeader::DISTRIBUTED_IDEMPOTENCY_KEY)
                : $message->getHeaders()->get(MessageHeaders::MESSAGE_ID);
        }

        /** @var Deduplicated $deduplicatedAttribute */
        $deduplicatedAttribute = $deduplicated->getAttribute();
        if ($deduplicated->hasExpression()) {
            return (string) $deduplicated->execute($message);
        }

        if ($deduplicatedAttribute->deduplicationHeaderName !== '') {
            return $message->getHeaders()->get($deduplicatedAttribute->deduplicationHeaderName);
        }

        return $this->idempotencyKeyOrMessageId($message);
    }

    private function determineRoutingSlip(Message $message, ?AttributeExpressionExecutor $deduplicated, ?IdentifiedAnnotation $identifiedAnnotation): string
    {
        if ($deduplicated !== null || ! $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP)) {
            return $identifiedAnnotation === null ? '' : $identifiedAnnotation->getEndpointId();
        }

        if ($this->isDistributedInboundWithIdempotencyKey($message)) {
            return $message->getHeaders()->get(DistributedBusHeader::DISTRIBUTED_SOURCE_SERVICE_NAME)
                . ':' . $message->getHeaders()->get(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY);
        }

        return $message->getHeaders()->get(MessageHeaders::ROUTING_SLIP);
    }

    private function isDistributedInboundWithIdempotencyKey(Message $message): bool
    {
        return $message->getHeaders()->containsKey(DistributedBusHeader::DISTRIBUTED_IDEMPOTENCY_KEY)
            && $message->getHeaders()->containsKey(DistributedBusHeader::DISTRIBUTED_ROUTING_KEY)
            && $message->getHeaders()->containsKey(DistributedBusHeader::DISTRIBUTED_SOURCE_SERVICE_NAME)
            && $message->getHeaders()->containsKey(MessageHeaders::ROUTING_SLIP)
            && $message->getHeaders()->get(MessageHeaders::ROUTING_SLIP) === DistributedBusHeader::DISTRIBUTED_ROUTING_SLIP_VALUE;
    }

    private function idempotencyKeyOrMessageId(Message $message): string
    {
        return $message->getHeaders()->containsKey(DistributedBusHeader::DISTRIBUTED_IDEMPOTENCY_KEY)
            ? $message->getHeaders()->get(DistributedBusHeader::DISTRIBUTED_IDEMPOTENCY_KEY)
            : $message->getHeaders()->get(MessageHeaders::MESSAGE_ID);
    }

    private function determineConsumerEndpointId(?AttributeExpressionExecutor $deduplicated, ?AsynchronousRunningEndpoint $asynchronousRunningEndpoint): string
    {
        /** @var Deduplicated|null $deduplicatedAttribute */
        $deduplicatedAttribute = $deduplicated?->getAttribute();
        // If trackingName is provided, use it for deduplication isolation
        if ($deduplicatedAttribute?->trackingName !== null) {
            return $deduplicatedAttribute->trackingName;
        }

        // If global deduplication consumer_endpoint_id will be used
        return $asynchronousRunningEndpoint ? $asynchronousRunningEndpoint->getEndpointId() : '';
    }
}
