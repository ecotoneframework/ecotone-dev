<?php

namespace Ecotone\DataProtection;

use Ecotone\DataProtection\Obfuscator\MessageObfuscator;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\Support\MessageBuilder;

class OutboundDecryptionChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private MessageObfuscator $messageObfuscator)
    {
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        if (! $this->canHandle($message)) {
            return $message;
        }

        $payload = $this->messageObfuscator->decrypt($message);

        $preparedMessage = MessageBuilder::withPayload($payload)
            ->setMultipleHeaders($message->getHeaders()->headers())
        ;

        return $preparedMessage->build();
    }

    private function canHandle(Message $message): bool
    {
        return $message->getHeaders()->containsKey('contentType') && MediaType::parseMediaType($message->getHeaders()->get('contentType'))->isCompatibleWith(MediaType::createApplicationJson());
    }
}
