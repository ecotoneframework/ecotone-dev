<?php

namespace Ecotone\Messaging\Handler\Processor;

use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;

class SendToChannelProcessor implements RealMessageProcessor
{
    public function __construct(
        private MessageChannel $channel,
    )
    {
    }

    public function process(Message $message): ?Message
    {
        $this->channel->send($message);
        return null;
    }
}