<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Kafka\Api\KafkaHeader;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingFailedException;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Ecotone\Modelling\AggregateFlow\AggregateIdMetadata;
use Ecotone\Modelling\AggregateMessage;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use Throwable;

/**
 * licence Enterprise
 */
final class KafkaOutboundChannelAdapter implements MessageHandler
{
    private const POLL_EVERY_PRODUCED_MESSAGES = 100;

    public function __construct(
        private string $referenceName,
        private KafkaAdmin                  $kafkaAdmin,
        private ConversionService           $conversionService,
        private OutboundMessageConverter $outboundMessageConverter,
        private AsyncPublishingRegistry $asyncPublishingRegistry,
    ) {
    }

    /**
     * Handles given message
     */
    public function handle(Message $message): void
    {
        $producer = $this->kafkaAdmin->getProducer($this->referenceName);
        $topic = $this->kafkaAdmin->getTopicForProducer($this->referenceName);

        if ($message->getPayload() instanceof BatchMessage) {
            $this->handleBatch($message->getPayload(), $producer, $topic);

            return;
        }

        $deliveryId = $this->produce($message, $topic, trackDelivery: true);
        $producer->poll(0);

        if ($this->canPublishAsynchronously()) {
            $this->registerPendingDelivery($producer, [$deliveryId]);

            return;
        }

        $this->flushSynchronously($producer, [$deliveryId]);
    }

    public function isAsyncPublishingEnabled(): bool
    {
        return $this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->isAsyncPublishingEnabled();
    }

    private function handleBatch(BatchMessage $batchMessage, Producer $producer, ProducerTopic $topic): void
    {
        $deliveryIds = [];
        try {
            $producedMessages = 0;
            foreach ($batchMessage->getEntries() as $entry) {
                $entryMessage = MessageBuilder::withPayload($entry['payload'])
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

        if ($this->canPublishAsynchronously()) {
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
            $retryDeadline = microtime(true) + ($this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->getAsyncPublishingTimeout() / 1000);
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

    private function canPublishAsynchronously(): bool
    {
        return $this->isAsyncPublishingEnabled() && $this->asyncPublishingRegistry->isScopeActive();
    }

    /**
     * @param string[] $deliveryIds
     */
    private function registerPendingDelivery(Producer $producer, array $deliveryIds): void
    {
        $this->asyncPublishingRegistry->register(
            $this->referenceName,
            new KafkaPendingDelivery(
                $producer,
                $this->kafkaAdmin->getDeliveryTracker($this->referenceName),
                $deliveryIds,
                $this->kafkaAdmin->getConfigurationForPublisher($this->referenceName)->getAsyncPublishingTimeout(),
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
                if ($this->isAsyncPublishingEnabled()) {
                    throw AsyncPublishingFailedException::withFailedDeliveries($deliveryResult->getFailedDeliveries());
                }

                throw MessagePublishingException::create(sprintf(
                    'Failed to send message to Kafka: %s',
                    $deliveryResult->getFailedDeliveries()[0]->getFailureReason(),
                ));
            }
        }

        if ($result !== 0) {
            throw MessagePublishingException::create('Failed to send message to Kafka');
        }
    }
}
