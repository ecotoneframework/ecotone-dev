<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\BatchMessage;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessage;
use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Queue\Context;
use Interop\Queue\Destination;

use function spl_object_id;

/**
 * licence Apache-2.0
 */
abstract class EnqueueOutboundChannelAdapter implements MessageHandler
{
    private array $initialized = [];

    public function __construct(
        protected CachedConnectionFactory  $connectionFactory,
        protected Destination              $destination,
        protected bool                     $autoDeclare,
        protected OutboundMessageConverter $outboundMessageConverter,
        private ConversionService $conversionService,
        private bool $batchPublishing = false,
        private string $channelName = '',
    ) {
    }

    abstract public function initialize(): void;

    public function handle(Message $message): void
    {
        if ($message->getPayload() instanceof BatchMessage && ! $this->batchPublishing) {
            throw ConfigurationException::create(sprintf('Sending BatchMessage over `%s` requires batch publishing to be enabled. Enable it with withHighThroughputPublishing(), available as part of Ecotone Enterprise.', $this->channelName));
        }

        $context = $this->createOutboundContext();

        if ($message->getPayload() instanceof BatchMessage) {
            $this->handleBatch($message->getPayload(), $context);
        } else {
            $this->sendSingleMessage($message, $context);
        }
    }

    protected function createOutboundContext(): Context
    {
        $context = $this->connectionFactory->createContext();
        if ($this->autoDeclare) {
            $contextId = spl_object_id($context);

            if (! isset($this->initialized[$contextId])) {
                $this->initialize();
                $this->initialized[$contextId] = true;
            }
        }

        return $context;
    }

    protected function handleBatch(BatchMessage $batchMessage, Context $context): void
    {
        foreach ($batchMessage->getEntries() as $entry) {
            $this->sendSingleMessage($this->convertBatchEntryToMessage($entry), $context);
        }
    }

    protected function convertBatchEntryToMessage(array $entry): Message
    {
        return MessageBuilder::withPayload($entry['payload'])
            ->setMultipleHeaders($entry['headers'])
            ->build();
    }

    protected function prepareOutboundMessage(Message $message): OutboundMessage
    {
        return $this->outboundMessageConverter->prepare($message, $this->conversionService);
    }

    /**
     * @param array{payload: mixed, headers: array<string, mixed>} $entry
     */
    protected function prepareOutboundMessageFromBatchEntry(array $entry): OutboundMessage
    {
        return $this->outboundMessageConverter->prepareFromBatchEntry($entry, $this->conversionService);
    }

    protected function sendSingleMessage(Message $message, Context $context): void
    {
        $outboundMessage                       = $this->prepareOutboundMessage($message);
        $headers                               = $outboundMessage->getHeaders();
        $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

        $messageToSend = $context->createMessage($outboundMessage->getPayload(), $headers, []);

        $context->createProducer()
            ->setTimeToLive($outboundMessage->getTimeToLive())
            ->setDeliveryDelay($outboundMessage->getDeliveryDelay())
            ->send($this->destination, $messageToSend);
    }
}
