<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Channel;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;
use Ecotone\Messaging\Support\MessageBuilder;
use Interop\Queue\ConnectionFactory;

final class FakeMessageChannelWithConnectionFactory implements PollableChannel
{
    /** @var Message[] */
    private array $messages = [];

    public function __construct(public $channelName, public ConnectionFactory $connectionFactory)
    {

    }

    public function send(Message $message): void
    {
        $this->messages[] = MessageBuilder::fromMessage($message)
            ->setHeader("connectionContext", $this->connectionFactory->createContext())
            ->build();
    }

    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }

    public function receive(): ?Message
    {
        if (empty($this->messages)) {
            return null;
        }

        $connectionContext = $this->messages[0]->getHeaders()->get('connectionContext');
        if ($connectionContext !== $this->connectionFactory->createContext()) {
            return null;
        }

        return array_shift($this->messages);
    }
}