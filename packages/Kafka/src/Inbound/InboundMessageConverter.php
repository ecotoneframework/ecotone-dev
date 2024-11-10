<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
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
        KafkaAdmin $kafkaAdmin,
        string $endpointId,
        private string $acknowledgeHeaderName,
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

        if (in_array($this->acknowledgeMode, [KafkaAcknowledgementCallback::AUTO_ACK, KafkaAcknowledgementCallback::MANUAL_ACK])) {
            if ($this->acknowledgeMode == KafkaAcknowledgementCallback::AUTO_ACK) {
                $amqpAcknowledgeCallback = KafkaAcknowledgementCallback::createWithAutoAck($consumer, $source, $this->loggingGateway);
            } else {
                $amqpAcknowledgeCallback = KafkaAcknowledgementCallback::createWithManualAck($consumer, $source, $this->loggingGateway);
            }

            $messageBuilder = $messageBuilder
                ->setHeader($this->acknowledgeHeaderName, $amqpAcknowledgeCallback);
        }

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

        return $messageBuilder
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, $this->acknowledgeHeaderName);
    }
}
