<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Api\Kafka\KafkaHeader;
use Ecotone\Api\Kafka\KafkaPublisherConfiguration;
use Ecotone\Api\Messaging\BatchMessage;
use Ecotone\Api\Messaging\DestinationDoesNotExistException;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Channel\DeliveryConfirmation\FailedDelivery;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PendingDeliveryRegistry;
use Ecotone\Messaging\Channel\DeliveryConfirmation\PublishingFailedException;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\AggregateIdMetadata;
use Ecotone\Modelling\AggregateMessage;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
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
final class KafkaOutboundChannelAdapter implements MessageHandler
{
    private const POLL_EVERY_PRODUCED_MESSAGES = 100;
    private const METADATA_TIMEOUT_IN_MILLISECONDS = 10000;

    private bool $destinationVerified = false;

    public function __construct(
        private string $referenceName,
        private KafkaAdmin                  $kafkaAdmin,
        private ConversionService           $conversionService,
        private OutboundMessageConverter $outboundMessageConverter,
        private PendingDeliveryRegistry $pendingDeliveryRegistry,
        private MessageBuilder $messageBuilder,
        private bool $requireExistingDestination = false,
    ) {
    }

    /**
     * Handles given message
     */
    public function handle(Message $message): void
    {
        if ($message->getPayload() instanceof BatchMessage && ! $this->isBatchPublishingEnabled()) {
            throw ConfigurationException::create(sprintf('Sending BatchMessage over `%s` requires batch publishing to be enabled. Enable it with withHighThroughputPublishing(), available as part of Ecotone Enterprise.', $this->referenceName));
        }

        $producer = $this->kafkaAdmin->getProducer($this->referenceName);
        $this->verifyDestinationExists($producer);
        $topic = $this->kafkaAdmin->getTopicForProducer($this->referenceName);

        if ($message->getPayload() instanceof BatchMessage) {
            $this->handleBatch($message->getPayload(), $producer, $topic);

            return;
        }

        $deliveryId = $this->produce($message, $topic, trackDelivery: true);
        $producer->poll(0);

        if ($this->canDeferConfirmation()) {
            $this->registerPendingDelivery($producer, [$deliveryId]);

            return;
        }

        $this->flushSynchronously($producer, [$deliveryId]);
    }

    private function verifyDestinationExists(Producer $producer): void
    {
        if (! $this->requireExistingDestination || $this->destinationVerified) {
            return;
        }

        $topicName = $this->kafkaAdmin->getTopicNameForProducer($this->referenceName);
        $existingTopicNames = [];
        foreach ($producer->getMetadata(true, null, self::METADATA_TIMEOUT_IN_MILLISECONDS)->getTopics() as $existingTopic) {
            $existingTopicNames[] = $existingTopic->getTopic();
        }

        if (! in_array($topicName, $existingTopicNames, true)) {
            $brokerConfigurationReference = $this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->brokerConfigurationReference;
            throw DestinationDoesNotExistException::forChannel(sprintf(
                "Channel '%s' requires its destination to exist, but topic '%s' does not exist on Kafka broker configuration '%s', so nothing was sent. Run the consumer of this channel (ecotone:run %s) or create the topic yourself, or fix the topic name (KafkaMessageChannelBuilder::create('%s', topicName: ...)). To let this channel create the topic on send, remove withRequiredExistingDestination().",
                $this->referenceName,
                $topicName,
                $brokerConfigurationReference,
                $this->referenceName,
                $this->referenceName,
            ), $this->referenceName, $topicName, $brokerConfigurationReference);
        }

        $this->destinationVerified = true;
    }

    public function isBatchPublishingEnabled(): bool
    {
        return $this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->isBatchPublishingEnabled();
    }

    public function isNonBlockingConfirmationEnabled(): bool
    {
        return $this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->isNonBlockingConfirmationEnabled();
    }

    private function handleBatch(BatchMessage $batchMessage, Producer $producer, ProducerTopic $topic): void
    {
        $deliveryIds = [];
        try {
            $producedMessages = 0;
            foreach ($batchMessage->entries as $entry) {
                $entryMessage = $this->messageBuilder->withPayload($entry['payload'])
                    ->setMultipleHeaders($entry['headers'])
                    ->build();

                $deliveryIds[] = $this->produce($entryMessage, $topic, trackDelivery: true);
                if (++$producedMessages % self::POLL_EVERY_PRODUCED_MESSAGES === 0) {
                    $producer->poll(0);
                }
            }
            $producer->poll(0);
        } catch (Throwable $exception) {
            if ($deliveryIds !== []) {
                $this->registerPendingDelivery($producer, $deliveryIds);
            }

            throw $exception;
        }

        if ($this->canDeferConfirmation()) {
            $this->registerPendingDelivery($producer, $deliveryIds);

            return;
        }

        $this->flushSynchronously($producer, $deliveryIds);
    }

    private function produce(Message $message, ProducerTopic $topic, bool $trackDelivery): ?string
    {
        $outboundMessage = $this->outboundMessageConverter->prepare($message, $this->conversionService);

        if ($message->getHeaders()->containsKey(KafkaHeader::KAFKA_TARGET_PARTITION_KEY_HEADER_NAME)) {
            $partitionKey = $message->getHeaders()->get(KafkaHeader::KAFKA_TARGET_PARTITION_KEY_HEADER_NAME);
        } elseif ($message->getHeaders()->containsKey(AggregateMessage::AGGREGATE_ID)) {
            $partitionKey = implode(',', array_values(AggregateIdMetadata::createFrom($message->getHeaders()->get(AggregateMessage::AGGREGATE_ID))->getIdentifiers()));
        } elseif ($message->getHeaders()->containsKey(MessageHeaders::EVENT_AGGREGATE_ID)) {
            $partitionKey = $message->getHeaders()->get(MessageHeaders::EVENT_AGGREGATE_ID);
        } else {
            $partitionKey = $message->getHeaders()->getMessageId();
        }

        $headers = array_filter($outboundMessage->getHeaders(), fn (mixed $headerValue) => $headerValue !== null);
        unset($headers[KafkaHeader::KAFKA_TARGET_PARTITION_KEY_HEADER_NAME]);

        $deliveryId = $trackDelivery
            ? $this->kafkaAdmin->getDeliveryTracker($this->referenceName)->trackInFlight($message)
            : null;

        try {
            $retryDeadline = microtime(true) + ($this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->getConfirmationTimeout() / 1000);
            while (true) {
                try {
                    $this->produceTracked($topic, $outboundMessage, $partitionKey, $headers, $deliveryId);

                    break;
                } catch (\RdKafka\Exception $exception) {
                    if ($exception->getCode() !== RD_KAFKA_RESP_ERR__QUEUE_FULL || microtime(true) >= $retryDeadline) {
                        throw $exception;
                    }

                    $this->kafkaAdmin->getProducer($this->referenceName)->poll(100);
                }
            }
        } catch (Throwable $exception) {
            if ($deliveryId !== null) {
                $this->kafkaAdmin->getDeliveryTracker($this->referenceName)->discard($deliveryId);
            }

            throw $exception;
        }

        return $deliveryId;
    }

    private function produceTracked(ProducerTopic $topic, \Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessage $outboundMessage, mixed $partitionKey, array $headers, ?string $deliveryId): void
    {
        $topic->producev(
            RD_KAFKA_PARTITION_UA,
            0,
            $outboundMessage->getPayload(),
            (string)$partitionKey,
            array_merge(
                $headers,
                [
                    KafkaHeader::KAFKA_SOURCE_PARTITION_KEY_HEADER_NAME => $partitionKey,
                ]
            ),
            null,
            $deliveryId,
        );
    }

    private function canDeferConfirmation(): bool
    {
        return $this->isNonBlockingConfirmationEnabled() && $this->pendingDeliveryRegistry->isScopeActive();
    }

    /**
     * @param string[] $deliveryIds
     */
    private function registerPendingDelivery(Producer $producer, array $deliveryIds): void
    {
        $this->pendingDeliveryRegistry->register(
            $this->referenceName,
            new KafkaPendingDelivery(
                $producer,
                $this->kafkaAdmin->getDeliveryTracker($this->referenceName),
                $deliveryIds,
                $this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->getConfirmationTimeout(),
                $this->referenceName,
            ),
        );
    }

    /**
     * @param string[] $deliveryIds
     */
    private function flushSynchronously(Producer $producer, array $deliveryIds = []): void
    {
        /**
         * Producer won't produce the message to the broker immediately it will wait until the producer queue (queue.buffering.max.messages)gets full or size of the queue(queue.buffering.max.kbytes).
         * calling flush immediately after produce will publish all messages to the broker irrespective of these two config values.
         */
        $result = $producer->flush((int)(KafkaPublisherConfiguration::ACKNOWLEDGE_TIMEOUT * 1.5));

        if ($deliveryIds !== []) {
            $deliveryResult = $this->kafkaAdmin->getDeliveryTracker($this->referenceName)->collectResult($deliveryIds, $this->referenceName);
            if (! $deliveryResult->isSuccessful()) {
                if ($this->isNonBlockingConfirmationEnabled()) {
                    throw PublishingFailedException::withFailedDeliveries($deliveryResult->getFailedDeliveries());
                }

                throw MessagePublishingException::create(sprintf(
                    'Failed to deliver %d message(s) to Kafka: %s',
                    count($deliveryResult->getFailedDeliveries()),
                    implode('; ', array_unique(array_map(
                        fn (FailedDelivery $failedDelivery): string => $failedDelivery->getFailureReason(),
                        $deliveryResult->getFailedDeliveries(),
                    ))),
                ));
            }
        }

        if ($result !== 0) {
            throw MessagePublishingException::create('Failed to send message to Kafka');
        }
    }

    public function __toString(): string
    {
        return "kafka outbound: {$this->referenceName}";
    }
}
