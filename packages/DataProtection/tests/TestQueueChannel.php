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

    public function __construct(string $name = 'unknown', bool $batchMessagesSupport = false)
    {
        parent::__construct($name, $batchMessagesSupport);
    }

    public static function create(string $name = 'unknown', bool $batchMessagesSupport = false): self
    {
        return new self($name, $batchMessagesSupport);
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
