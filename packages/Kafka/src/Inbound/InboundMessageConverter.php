<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Api\KafkaHeader;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use RdKafka\KafkaConsumer;
use RdKafka\Message as KafkaMessage;

/**
 * licence Enterprise
 */
final class InboundMessageConverter
{
    private string $acknowledgeMode;
    private HeaderMapper $headerMapper;

    public function __construct(
        private KafkaAdmin $kafkaAdmin,
        private string $endpointId,
        private string $acknowledgeHeaderName,
        private FinalFailureStrategy $finalFailureStrategy,
        private LoggingGateway $loggingGateway,
    ) {
        $kafkaConsumerConfiguration = $kafkaAdmin->getConfigurationForConsumer($endpointId);
        $this->acknowledgeMode = $kafkaConsumerConfiguration->getAcknowledgeMode();
        $this->headerMapper = $kafkaConsumerConfiguration->getHeaderMapper();
    }

    public function toMessage(
        KafkaConsumer $consumer,
        KafkaMessage $source,
        ConversionService $conversionService
    ): MessageBuilder {
        $messageHeaders = $source->headers ?? [];
        $messageBuilder = MessageBuilder::withPayload($source->payload)
            ->setMultipleHeaders($this->headerMapper->mapToMessageHeaders($messageHeaders, $conversionService));

        $amqpAcknowledgeCallback = KafkaAcknowledgementCallback::create($consumer, $source, $this->loggingGateway, $this->kafkaAdmin, $this->endpointId, $this->finalFailureStrategy, $this->acknowledgeMode === KafkaAcknowledgementCallback::AUTO_ACK);

        $messageBuilder = $messageBuilder
            ->setHeader($this->acknowledgeHeaderName, $amqpAcknowledgeCallback)
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, $this->acknowledgeHeaderName)
            ->setHeader(KafkaHeader::TOPIC_HEADER_NAME, $source->topic_name)
            ->setHeader(KafkaHeader::PARTITION_HEADER_NAME, $source->partition)
            ->setHeader(KafkaHeader::OFFSET_HEADER_NAME, $source->offset)
            ->setHeader(KafkaHeader::KAFKA_TIMESTAMP_HEADER_NAME, $source->timestamp);

        if (isset($messageHeaders[MessageHeaders::MESSAGE_ID])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::MESSAGE_ID, $messageHeaders[MessageHeaders::MESSAGE_ID]);
        }

        if (isset($messageHeaders[MessageHeaders::TIMESTAMP])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::TIMESTAMP, $messageHeaders[MessageHeaders::TIMESTAMP]);
        }

        if (isset($messageHeaders[MessageHeaders::MESSAGE_CORRELATION_ID])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::MESSAGE_CORRELATION_ID, $messageHeaders[MessageHeaders::MESSAGE_CORRELATION_ID]);
        }

        if (isset($messageHeaders[MessageHeaders::PARENT_MESSAGE_ID])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::PARENT_MESSAGE_ID, $messageHeaders[MessageHeaders::PARENT_MESSAGE_ID]);
        }

        return $messageBuilder;
    }
}
