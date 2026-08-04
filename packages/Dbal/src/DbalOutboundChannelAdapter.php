<?php

declare(strict_types=1);

namespace Ecotone\Dbal;

use Ecotone\Dbal\Database\EnqueueTableManager;
use Ecotone\Enqueue\CachedConnectionFactory;
use Ecotone\Enqueue\EnqueueOutboundChannelAdapter;
use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\AsyncPublishing\AsyncPublishingRegistry;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHeaders;
use Enqueue\Dbal\DbalContext;
use Enqueue\Dbal\DbalDestination;
use Enqueue\Dbal\DbalProducer;
use Interop\Queue\Context;

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
        AsyncPublishingRegistry $asyncPublishingRegistry,
        bool $asyncPublishing = false,
    ) {
        parent::__construct(
            $connectionFactory,
            new DbalDestination($this->queueName),
            $autoDeclare,
            $outboundMessageConverter,
            $conversionService,
            $asyncPublishingRegistry,
            $asyncPublishing,
            $this->queueName,
        );
    }

    public function initialize(): void
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        if (! $this->tableManager->shouldBeInitializedAutomatically()) {
            return;
        }

        $this->tableManager->createTable($context->getDbalConnection());
        $context->createQueue($this->queueName);
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
            $outboundMessage = $this->prepareOutboundMessage($this->convertBatchEntryToMessage($entry));
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
}
