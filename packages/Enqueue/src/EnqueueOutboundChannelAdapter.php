<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Channel\PollableChannel\Serialization\OutboundMessageConverter;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\MessageHeaders;
use Interop\Queue\Destination;

abstract class EnqueueOutboundChannelAdapter implements MessageHandler
{
    private bool $initialized = false;

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
        if ($this->autoDeclare && ! $this->initialized) {
            $this->initialize();
            $this->initialized = true;
        }

        $outboundMessage                       = $this->outboundMessageConverter->prepare($message, $this->conversionService);
        $headers                               = $outboundMessage->getHeaders();
        $headers[MessageHeaders::CONTENT_TYPE] = $outboundMessage->getContentType();

        $messageToSend = $this->connectionFactory->createContext()->createMessage($outboundMessage->getPayload(), $headers, []);

        $this->connectionFactory->getProducer()
            ->setTimeToLive($outboundMessage->getTimeToLive())
            ->setDeliveryDelay($outboundMessage->getDeliveryDelay())
            ->send($this->destination, $messageToSend);
    }
}
