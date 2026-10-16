<?php

declare(strict_types=1);

namespace Ecotone\Kafka\Inbound;

use Ecotone\Api\ExtensionObject\FinalFailureStrategy;
use Ecotone\Api\Kafka\KafkaHeader;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Kafka\Configuration\KafkaAdmin;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\Support\MessageBuilder;
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
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class InboundMessageConverter
{
    public function __construct(
        private KafkaAdmin $kafkaAdmin,
        private string $acknowledgeHeaderName,
        private FinalFailureStrategy $finalFailureStrategy,
        private LoggingGateway $loggingGateway,
        private MessageBuilder $messageBuilder,
    ) {
    }

    public function toMessage(
        string $endpointId,
        string $channelName,
        KafkaConsumer $consumer,
        KafkaMessage $source,
        ConversionService $conversionService,
        BatchCommitCoordinator $batchCommitCoordinator,
    ): MessageBuilder {
        $kafkaConsumerConfiguration = $this->kafkaAdmin->getRdKafkaConfiguration($channelName);
        $headerMapper = $kafkaConsumerConfiguration->getHeaderMapper();
        $acknowledgeMode = $kafkaConsumerConfiguration->getAcknowledgeMode();

        $messageHeaders = $source->headers ?? [];
        $messageBuilder = $this->messageBuilder->withPayload($source->payload)
            ->setMultipleHeaders($headerMapper->mapToMessageHeaders($messageHeaders, $conversionService));

        $amqpAcknowledgeCallback = KafkaAcknowledgementCallback::create(
            $consumer,
            $source,
            $this->loggingGateway,
            $this->kafkaAdmin,
            $endpointId,
            $channelName,
            $this->finalFailureStrategy,
            $acknowledgeMode === KafkaAcknowledgementCallback::AUTO_ACK,
            $batchCommitCoordinator,
        );

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
