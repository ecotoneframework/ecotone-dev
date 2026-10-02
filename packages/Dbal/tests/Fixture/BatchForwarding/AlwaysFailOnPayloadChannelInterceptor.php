<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\BatchForwarding;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use RuntimeException;

/**
 * licence Apache-2.0
 */
final class AlwaysFailOnPayloadChannelInterceptor extends AbstractChannelInterceptor
{
    public function __construct(private string $failingPayload)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        if ($message->getPayload() === $this->failingPayload) {
            throw new RuntimeException('Delivery of ' . $this->failingPayload . ' failed');
        }

        return $message;
    }
}
