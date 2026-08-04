<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\BatchForwarding;

use Ecotone\Messaging\Channel\QueueChannel;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Message;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class FailOnceOnPayloadPollableChannel extends QueueChannel
{
    public function __construct(private string $failingPayload, private int $failuresLeft = 2)
    {
        parent::__construct('failingProcessing');
    }

    public function send(Message $message): void
    {
        if ($this->failuresLeft > 0 && $message->getPayload() === $this->failingPayload) {
            $this->failuresLeft--;

            throw new RuntimeException('Delivery of ' . $this->failingPayload . ' failed');
        }

        parent::send($message);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->failingPayload, $this->failuresLeft]);
    }
}
