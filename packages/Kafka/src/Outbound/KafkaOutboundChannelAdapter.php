<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Kafka\Configuration\KafkaBrokerConfiguration;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;

/**
 * licence Enterprise
 */
final class KafkaOutboundChannelAdapter implements MessageHandler
{
    public function __construct(
        private string $referenceName,
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
        $producer = $this->kafkaAdmin->getProducer($this->referenceName, $this->brokerConfiguration);
        $topic = $this->kafkaAdmin->getTopicForProducer($this->referenceName, $this->brokerConfiguration);
        $outboundMessage = $this->outboundMessageConverter->prepare($message, $this->conversionService);

        $topic->producev(
            RD_KAFKA_PARTITION_UA,
            0,
            $outboundMessage->getPayload(),
            $message->getHeaders()->getMessageId(),
            $outboundMessage->getHeaders(),
        );

        $producer->flush(5000);
    }
}
