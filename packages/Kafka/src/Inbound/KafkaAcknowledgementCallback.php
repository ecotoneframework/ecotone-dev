<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Exception;
use RdKafka\KafkaConsumer;
use RdKafka\Message as KafkaMessage;

/**
 * licence Enterprise
 */
class KafkaAcknowledgementCallback implements AcknowledgementCallback
{
    public const AUTO_ACK = 'auto';
    public const MANUAL_ACK = 'manual';
    public const NONE = 'none';

    private function __construct(
        private FinalFailureStrategy $failureStrategy,
        private bool $isAutoAcked,
        private KafkaConsumer        $consumer,
        private KafkaMessage         $message,
        private LoggingGateway       $loggingGateway,
        private KafkaAdmin           $kafkaAdmin,
        private string               $endpointId,
        private BatchCommitCoordinator $batchCommitCoordinator,
    ) {
    }

    public static function create(
        KafkaConsumer $consumer,
        KafkaMessage $message,
        LoggingGateway $loggingGateway,
        KafkaAdmin $kafkaAdmin,
        string $endpointId,
        FinalFailureStrategy $finalFailureStrategy,
        bool $isAutoAcked,
        BatchCommitCoordinator $batchCommitCoordinator,
    ): self {
        return new self(
            $finalFailureStrategy,
            $isAutoAcked,
            $consumer,
            $message,
            $loggingGateway,
            $kafkaAdmin,
            $endpointId,
            $batchCommitCoordinator,
        );
    }

    /**
     * @inheritDoc
     */
    public function getFailureStrategy(): FinalFailureStrategy
    {
        return $this->failureStrategy;
    }

    /**
     * @inheritDoc
     */
    public function isAutoAcked(): bool
    {
        return $this->isAutoAcked;
    }

    /**
     * @inheritDoc
     */
    public function accept(): void
    {
        try {
            $this->batchCommitCoordinator->increaseMessageHandling($this->message);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to acknowledge message. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     */
    public function reject(): void
    {
        try {
            $this->batchCommitCoordinator->increaseMessageHandling($this->message);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to skip message. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     */
    public function resend(): void
    {
        try {
            $this->kafkaAdmin->getProducer($this->endpointId);
            $topic = $this->kafkaAdmin->getTopicForProducer($this->endpointId);
            $topic->producev(
                $this->message->partition,
                0,
                $this->message->payload,
                $this->message->key,
                $this->message->headers,
            );

            $this->batchCommitCoordinator->increaseMessageHandling($this->message);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to requeue message. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     * Resets the offset to redeliver the same message
     */
    public function release(): void
    {
        try {
            $this->batchCommitCoordinator->forceCommitAll();

            //            // Do not use "assign", as it will switch from automatic assignment to manual
            $this->kafkaAdmin->closeConsumer($this->endpointId);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to reset offset for message redelivery. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }
}
