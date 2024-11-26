<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;

/**
 * licence Enterprise
 */
final class KafkaOutboundChannelAdapter implements MessageHandler
{
    private OutboundMessageConverter $outboundMessageConverter;

    public function __construct(
        private string $referenceName,
        private KafkaAdmin                  $kafkaAdmin,
        private ConversionService           $conversionService
    ) {
        $headerMapper = $kafkaAdmin->getConfigurationForPublisher($referenceName)->getHeaderMapper();

        $this->outboundMessageConverter = new OutboundMessageConverter($headerMapper);
    }

    /**
     * Handles given message
     */
    public function handle(Message $message): void
    {
        $producer = $this->kafkaAdmin->getProducer($this->referenceName);
        $topic = $this->kafkaAdmin->getTopicForProducer($this->referenceName);
        $outboundMessage = $this->outboundMessageConverter->prepare($message, $this->conversionService);

        $topic->producev(
            RD_KAFKA_PARTITION_UA,
            0,
            $outboundMessage->getPayload(),
            $message->getHeaders()->getMessageId(),
            $outboundMessage->getHeaders(),
        );


        // calculate time to wait for ack
        //        $start = microtime(true);

        /**
         * Producer won't produce the message to the broker immediately it will wait until the producer queue (queue.buffering.max.messages)gets full or size of the queue(queue.buffering.max.kbytes).
         * calling flush immediately after produce will publish all messages to the broker irrespective of these two config values.
         */
        $result = $producer->flush((int)(KafkaPublisherConfiguration::ACKNOWLEDGE_TIMEOUT * 1.5));
        if ($result !== 0) {
            throw MessagePublishingException::create('Failed to send message to Kafka');
        }

        //        $end = microtime(true);
        //        $timeToWait = ($end - $start) * 1000;
        //        dd($timeToWait);
    }
}
