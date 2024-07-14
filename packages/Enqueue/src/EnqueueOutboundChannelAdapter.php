<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
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
        private ConversionService $conversionService
    ) {
    }

    abstract public function initialize(): void;

    public function handle(Message $message): void
    {
        $context = $this->connectionFactory->createContext();
        if ($this->autoDeclare) {
            $contextId = spl_object_id($context);

            if (! isset($this->initialized[$contextId])) {
                $this->initialize();
                $this->initialized[$contextId] = true;
            }
        }

        $outboundMessage                       = $this->outboundMessageConverter->prepare($message, $this->conversionService);
        $headers                               = $outboundMessage->getHeaders();
        $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

        $messageToSend = $context->createMessage($outboundMessage->getPayload(), $headers, []);

        $context->createProducer()
            ->setTimeToLive($outboundMessage->getTimeToLive())
            ->setDeliveryDelay($outboundMessage->getDeliveryDelay())
            ->send($this->destination, $messageToSend);
    }
}
