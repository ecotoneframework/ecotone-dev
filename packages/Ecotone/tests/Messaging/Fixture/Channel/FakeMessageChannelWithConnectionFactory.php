<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Channel;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Queue\ConnectionFactory;

final class FakeMessageChannelWithConnectionFactory implements PollableChannel
{
    public function __construct(
        public $channelName,
        public ConnectionFactory $connectionFactory,
    )
    {

    }

    public function send(Message $message): void
    {
        $this->getContextChannel()->send($message);
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }

    public function receive(): ?Message
    {
        return $this->getContextChannel()->receive();
    }

    private function getContextChannel(): PollableChannel
    {
        return $this->connectionFactory->createContext();
    }
}