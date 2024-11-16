<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
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
        private bool           $isAutoAck,
        private KafkaConsumer  $consumer,
        private KafkaMessage   $message,
        private LoggingGateway $loggingGateway,
        private KafkaAdmin     $kafkaAdmin,
        private string         $endpointId
    ) {
    }

    public static function createWithAutoAck(KafkaConsumer $consumer, KafkaMessage $message, LoggingGateway $loggingGateway, KafkaAdmin $kafkaAdmin, string $endpointId): self
    {
        return new self(true, $consumer, $message, $loggingGateway, $kafkaAdmin, $endpointId);
    }

    public static function createWithManualAck(KafkaConsumer $consumer, KafkaMessage $message, LoggingGateway $loggingGateway, KafkaAdmin $kafkaAdmin, string $endpointId): self
    {
        return new self(false, $consumer, $message, $loggingGateway, $kafkaAdmin, $endpointId);
    }

    /**
     * @inheritDoc
     */
    public function isAutoAck(): bool
    {
        return $this->isAutoAck;
    }

    /**
     * @inheritDoc
     */
    public function disableAutoAck(): void
    {
        $this->isAutoAck = false;
    }

    /**
     * @inheritDoc
     */
    public function accept(): void
    {
        try {
            $this->consumer->commit($this->message);
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
            $this->consumer->commit($this->message);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to skip message. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }

    /**
     * @inheritDoc
     */
    public function requeue(): void
    {
        try {
            //            what to do here?
            //            $this->consumer->pausePartitions([$this->message->partition]);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to requeue message, disconnecting Connection in order to self-heal. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }
}
