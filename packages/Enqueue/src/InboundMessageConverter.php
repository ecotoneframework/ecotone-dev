<?php

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Endpoint\FinalFailureStrategy;
use Ecotone\Messaging\Handler\Logger\LoggingGateway;
use Ecotone\Messaging\MessageConverter\HeaderMapper;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Queue\Consumer as EnqueueConsumer;
use Interop\Queue\Message as EnqueueMessage;

/**
 * licence Apache-2.0
 */
class InboundMessageConverter
{
    public function __construct(
        private string $inboundEndpointId,
        private string $acknowledgeMode,
        private HeaderMapper $headerMapper,
        private string $acknowledgeHeaderName,
        private LoggingGateway $loggingGateway,
        private FinalFailureStrategy $finalFailureStrategy = FinalFailureStrategy::RESEND,
    ) {

    }

    public function getFinalFailureStrategy(): FinalFailureStrategy
    {
        return $this->finalFailureStrategy;
    }

    public function toMessage(EnqueueMessage $source, EnqueueConsumer $consumer, ConversionService $conversionService, CachedConnectionFactory $connectionFactory): MessageBuilder
    {
        $enqueueMessageHeaders = $source->getProperties();
        $messageBuilder = MessageBuilder::withPayload($source->getBody())
            ->setMultipleHeaders($this->headerMapper->mapToMessageHeaders($enqueueMessageHeaders, $conversionService));

        $amqpAcknowledgeCallback = EnqueueAcknowledgementCallback::create(
            $consumer,
            $source,
            $connectionFactory,
            $this->loggingGateway,
            $this->finalFailureStrategy,
            $this->acknowledgeMode == EnqueueAcknowledgementCallback::AUTO_ACK
        );

        $messageBuilder = $messageBuilder
            ->setHeader($this->acknowledgeHeaderName, $amqpAcknowledgeCallback);

        if (isset($enqueueMessageHeaders[MessageHeaders::MESSAGE_ID])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::MESSAGE_ID, $enqueueMessageHeaders[MessageHeaders::MESSAGE_ID]);
        }

        if (isset($enqueueMessageHeaders[MessageHeaders::TIMESTAMP])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::TIMESTAMP, $enqueueMessageHeaders[MessageHeaders::TIMESTAMP]);
        }

        if (isset($enqueueMessageHeaders[MessageHeaders::MESSAGE_CORRELATION_ID])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::MESSAGE_CORRELATION_ID, $enqueueMessageHeaders[MessageHeaders::MESSAGE_CORRELATION_ID]);
        }

        if (isset($enqueueMessageHeaders[MessageHeaders::PARENT_MESSAGE_ID])) {
            $messageBuilder = $messageBuilder
                ->setHeader(MessageHeaders::PARENT_MESSAGE_ID, $enqueueMessageHeaders[MessageHeaders::PARENT_MESSAGE_ID]);
        }

        return $messageBuilder
            ->setHeader(MessageHeaders::CONSUMER_ACK_HEADER_LOCATION, $this->acknowledgeHeaderName);
    }
}
