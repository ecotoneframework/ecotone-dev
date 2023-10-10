<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;

class PollingConsumerChannel implements MessageChannel
{
    public function __construct(private PollingConsumerContext $pollingConsumerContext)
    {
    }

    public function send(Message $message): void
    {
        $this->pollingConsumerContext->getPollingConsumerHandler()->handle($message);
    }
}