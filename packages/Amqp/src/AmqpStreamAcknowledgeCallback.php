<?php

declare(strict_types=1);

namespace Ecotone\Amqp;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Exception;
use PhpAmqpLib\Message\AMQPMessage as PhpAmqpLibMessage;

/**
 * AMQP Stream Acknowledge Callback
 *
 * For stream consumption, messages need to be acknowledged immediately
 * to allow RabbitMQ to deliver subsequent messages when using QoS limits.
 * This callback implements the same interface as EnqueueAcknowledgementCallback
 * but handles immediate acknowledgment for stream messages.
 *
 * licence Apache-2.0
 */
class AmqpStreamAcknowledgeCallback implements AcknowledgementCallback
{
    private bool $isPositionCommitted = false;

    private function __construct(
        private PhpAmqpLibMessage $amqpMessage,
        private LoggingGateway $loggingGateway,
        private CachedConnectionFactory $connectionFactory,
        private FinalFailureStrategy $failureStrategy,
        private bool $isAutoAcked,
        private ConsumerPositionTracker $positionTracker,
        private string $endpointId,
        private ?string $streamOffset
    ) {
    }

    public static function create(
        PhpAmqpLibMessage $amqpMessage,
        LoggingGateway $loggingGateway,
        CachedConnectionFactory $connectionFactory,
        FinalFailureStrategy $failureStrategy,
        bool $isAutoAcked,
        ConsumerPositionTracker $positionTracker,
        string $endpointId,
        ?string $streamOffset
    ): self {
        return new self($amqpMessage, $loggingGateway, $connectionFactory, $failureStrategy, $isAutoAcked, $positionTracker, $endpointId, $streamOffset);
    }

    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->failureStrategy;
    }

    public function isAutoAcked(): bool
    {
        return $this->isAutoAcked;
    }

    public function accept(): void
    {
        $this->acknowledgeAmqpMessage();
        $this->commitPosition();
    }

    public function reject(): void
    {
        $this->rejectAmqpMessage();
        $this->commitPosition();
    }

    public function resend(): void
    {
        // For streams, resend is not applicable in the traditional sense
        // We'll just reject the message as streams don't support traditional requeuing
        $this->reject();
    }

    public function release(): void
    {
        // For streams, release is not applicable in the traditional sense
        // We'll just reject the message as streams don't support traditional requeuing
        $this->reject();
    }

    private function acknowledgeAmqpMessage(): void
    {
        try {
            $this->amqpMessage->ack();
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to acknowledge AMQP stream message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    private function rejectAmqpMessage(): void
    {
        try {
            // For streams, reject without requeue (streams don't support traditional requeuing)
            $this->amqpMessage->reject(false);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to reject AMQP stream message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            $this->connectionFactory->reconnect();

            throw $exception;
        }
    }

    private function commitPosition(): void
    {
        if ($this->isPositionCommitted || $this->streamOffset === null) {
            return;
        }

        // Commit next offset (current + 1) so we resume from the next message
        $nextOffset = (string)((int)$this->streamOffset + 1);
        $this->positionTracker->savePosition($this->endpointId, $nextOffset);
        $this->isPositionCommitted = true;
    }
}
