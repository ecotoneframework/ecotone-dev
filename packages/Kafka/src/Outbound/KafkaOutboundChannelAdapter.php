<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Kafka\Configuration\KafkaPublisherConfiguration;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Interop\Queue\Destination;
use RdKafka\Metadata\Topic;
use RdKafka\Producer;
use RdKafka\ProducerTopic;

final class KafkaOutboundChannelAdapter implements MessageHandler
{
    private ?Producer $producer = null;
    private ?ProducerTopic $topic = null;

    public function __construct(
        private KafkaPublisherConfiguration $publisherConfiguration,
        private KafkaAdmin                  $kafkaAdmin,
        private KafkaBrokerConfiguration    $brokerConfiguration,
        protected OutboundMessageConverter  $outboundMessageConverter,
        private ConversionService           $conversionService
    ) {
    }

    /**
     * Handles given message
     */
    public function handle(Message $message): void
    {
        if (!$this->producer) {
            $conf = $this->publisherConfiguration->getAsKafkaConfig();
            $conf->set("bootstrap.servers", implode(",", $this->brokerConfiguration->getBootstrapServers()));
            $this->producer = new Producer($conf);

            $this->topic = $this->producer->newTopic(
                $this->publisherConfiguration->getDefaultTopicName(),
                $this->kafkaAdmin->getConfigurationForTopic($this->publisherConfiguration->getDefaultTopicName())
            );
        }

        $outboundMessage = $this->outboundMessageConverter->prepare($message, $this->conversionService);

        $this->topic->producev(
            RD_KAFKA_PARTITION_UA,
            0,
            $outboundMessage->getPayload(),
            $message->getHeaders()->getMessageId(),
            $outboundMessage->getHeaders(),
        );

        $this->producer->flush(5000);
    }
}