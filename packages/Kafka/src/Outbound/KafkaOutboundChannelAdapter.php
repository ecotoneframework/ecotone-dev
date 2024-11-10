<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Outbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
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

        $producer->flush(5000);
    }
}
