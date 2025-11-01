<?php

declare(strict_types=1);

namespace Ecotone\Enqueue;

use Ecotone\Messaging\Endpoint\PollingMetadata;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\PollableChannel;

/**
 * licence Apache-2.0
 */
final class EnqueueMessageChannel implements PollableChannel
{
    public function __construct(private EnqueueInboundChannelAdapter $inboundChannelAdapter, private MessageHandler $outboundChannelAdapter)
    {
    }

    public function send(Message $message): void
    {
        $this->outboundChannelAdapter->handle($message);
    }

    public function receive(): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout(PollingMetadata::create('enqueue'));
    }

    public function receiveWithTimeout(PollingMetadata $pollingMetadata): ?Message
    {
        return $this->inboundChannelAdapter->receiveWithTimeout($pollingMetadata);
    }

    public function onConsumerStop(): void
    {
        $this->inboundChannelAdapter->onConsumerStop();
    }

    public function __toString()
    {
        return 'enqueue: ' . $this->inboundChannelAdapter->getQueueName();
    }
}
