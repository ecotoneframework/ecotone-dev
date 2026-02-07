<?php

declare(strict_types=1);

namespace Test\Ecotone\DataProtection;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Message;

/**
 * Test implementation of QueueChannel for PHPUnit 10 compatibility
 */
class TestQueueChannel extends QueueChannel
{
    private ?Message $lastSentMessage = null;

    public function __construct(string $name = 'unknown')
    {
        parent::__construct($name);
    }

    public static function create(string $name = 'unknown'): self
    {
        return new self($name);
    }

    public function send(Message $message): void
    {
        $this->lastSentMessage = $message;

        parent::send($message);
    }

    public function receive(): ?Message
    {
        return parent::receive();
    }

    public function getLastSentMessage(): ?Message
    {
        return $this->lastSentMessage;
    }
}
