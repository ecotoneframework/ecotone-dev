<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Endpoint\AcknowledgementCallback;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Exception;
use RdKafka\KafkaConsumer;
use RdKafka\Message as KafkaMessage;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
        private string               $channelName,
        private BatchCommitCoordinator $batchCommitCoordinator,
    ) {
    }

    public static function create(
        KafkaConsumer $consumer,
        KafkaMessage $message,
        LoggingGateway $loggingGateway,
        KafkaAdmin $kafkaAdmin,
        string $endpointId,
        string $channelName,
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
            $channelName,
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
            $this->kafkaAdmin->getProducer($this->channelName);
            $topic = $this->kafkaAdmin->getTopicForProducer($this->channelName);
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

            $this->kafkaAdmin->closeConsumer($this->endpointId);
        } catch (Exception $exception) {
            $this->loggingGateway->info('Failed to reset offset for message redelivery. Failure happen due to: ' . $exception->getMessage(), ['exception' => $exception]);

            throw $exception;
        }
    }
}
