<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Ecotone\Api\Messaging\BatchMessage;
use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageHeaders;
use Ecotone\Dbal\Connection\DbalDestination;
use Ecotone\Dbal\Connection\DbalProducer;
use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Dbal\Database\MissingTableFailure;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Interop\Queue\Context;
use Throwable;

/**
 * licence Apache-2.0
 */
class DbalOutboundChannelAdapter extends EnqueueOutboundChannelAdapter
{
    public function __construct(
        CachedConnectionFactory $connectionFactory,
        private string $queueName,
        bool $autoDeclare,
        OutboundMessageConverter $outboundMessageConverter,
        ConversionService $conversionService,
        private EnqueueTableManager $tableManager,
        bool $batchPublishing = false,
    ) {
        parent::__construct(
            $connectionFactory,
            new DbalDestination($this->queueName),
            $autoDeclare,
            $outboundMessageConverter,
            $conversionService,
            $batchPublishing,
            $this->queueName,
        );
    }

    public function handle(Message $message): void
    {
        try {
            parent::handle($message);
        } catch (Throwable $failure) {
            throw MissingTableFailure::explained($failure, $this->tableManager);
        }
    }

    public function initialize(): void
    {
    }

    protected function sendSingleMessage(Message $message, Context $context): void
    {
        $this->handleBatch(
            BatchMessage::constructEmpty()->append($message->getPayload(), $message->getHeaders()->headers()),
            $context,
        );
    }

    protected function handleBatch(BatchMessage $batchMessage, Context $context): void
    {
        $messagesToSend = [];
        foreach ($batchMessage->getEntries() as $entry) {
            $outboundMessage = $this->prepareOutboundMessageFromBatchEntry($entry);
            $headers = $outboundMessage->getHeaders();
            $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

            $messageToSend = $context->createMessage($outboundMessage->getPayload(), $headers, []);
            $messageToSend->setDeliveryDelay($outboundMessage->getDeliveryDelay());
            $messageToSend->setTimeToLive($outboundMessage->getTimeToLive());

            $messagesToSend[] = $messageToSend;
        }

        /** @var DbalProducer $producer */
        $producer = $context->createProducer();
        $producer->sendBatch($this->destination, $messagesToSend);
    }

    public function __toString(): string
    {
        return "dbal outbound: {$this->queueName}";
    }
}
