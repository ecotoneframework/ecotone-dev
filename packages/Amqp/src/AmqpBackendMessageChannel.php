<?php

namespace Ecotone\Amqp;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;

class AmqpBackendMessageChannel implements PollableChannel
{
    public function __construct(private AmqpInboundChannelAdapter $amqpInboundChannelAdapter, private AmqpOutboundChannelAdapter $amqpOutboundChannelAdapter)
    {
    }

    /**
     * @inheritDoc
     */
    public function send(Message $message): void
    {
        $this->amqpOutboundChannelAdapter->handle($message);
    }

    /**
     * @inheritDoc
     */
    public function receive(): ?Message
    {
        return $this->amqpInboundChannelAdapter->receiveMessage();
    }

    /**
     * @inheritDoc
     */
    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->amqpInboundChannelAdapter->receiveMessage($timeoutInMilliseconds);
    }
}
