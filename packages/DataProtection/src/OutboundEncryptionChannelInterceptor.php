<?php

/**
 * licence Enterprise
 */
namespace Ecotone\DataProtection;

use Ecotone\DataProtection\Obfuscator\MessageObfuscator;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;
use Ecotone\Messaging\MessageHeaders;

class OutboundEncryptionChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private MessageObfuscator $messageObfuscator)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        if (! $this->canHandle($message)) {
            return $message;
        }

        return $this->messageObfuscator->encrypt($message);
    }

    private function canHandle(Message $message): bool
    {
        return $message->getHeaders()->containsKey(MessageHeaders::CONTENT_TYPE)
            && MediaType::parseMediaType($message->getHeaders()->get('contentType'))->isCompatibleWith(MediaType::createApplicationJson())
        ;
    }
}
