<?php

namespace Ecotone\Messaging\Channel;

use Ecotone\Messaging\Message;
use Ecotone\Messaging\PollableChannel;

class QueueChannel implements PollableChannel
{
    /**
     * @var Message[] $queue
     */
    private array $queue = [];

    public function __construct(private string $name)
    {
    }

    public static function create(string $name = 'unknown'): self
    {
        return new self($name);
    }

    /**
     * @inheritDoc
     */
    public function send(Message $message): void
    {
        $this->queue[] = $message;
    }

    /**
     * @inheritDoc
     */
    public function receive(): ?Message
    {
        return array_shift($this->queue);
    }

    /**
     * @inheritDoc
     */
    public function receiveWithTimeout(int $timeoutInMilliseconds): ?Message
    {
        return $this->receive();
    }

    public function __toString()
    {
        return 'queue channel: ' . $this->name;
    }
}
