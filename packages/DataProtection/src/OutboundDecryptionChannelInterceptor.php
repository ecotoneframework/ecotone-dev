<?php

/**
 * licence Enterprise
 */

namespace Ecotone\DataProtection;

use Ecotone\DataProtection\Protector\ChannelProtector;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Conversion\MediaType;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;

class OutboundDecryptionChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private readonly ChannelProtector $channelProtector)
    {
    }

    public function postReceive(Message $message, MessageChannel $messageChannel): ?Message
    {
        if ($message->getHeaders()->getContentType()?->isCompatibleWith(MediaType::createApplicationJson())) {
            return $this->channelProtector->decrypt($message);
        }

        return $message;
    }
}
