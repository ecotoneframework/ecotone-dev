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
final class FailingPollableChannel extends QueueChannel
{
    public function __construct()
    {
        parent::__construct('failingProcessing');
    }

    public function send(Message $message): void
    {
        throw new RuntimeException('Target channel is unavailable');
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class);
    }
}
