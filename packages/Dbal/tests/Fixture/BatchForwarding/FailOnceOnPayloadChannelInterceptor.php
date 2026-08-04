<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\BatchForwarding;

use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Ecotone\Messaging\Message;
use Ecotone\Messaging\MessageChannel;

/**
 * licence Apache-2.0
 */
final class FailOnceOnPayloadChannelInterceptor extends AbstractChannelInterceptor
{
    private bool $alreadyFailed = false;

    public function __construct(private string $failingPayload, private string $exceptionClass)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        if (! $this->alreadyFailed && $message->getPayload() === $this->failingPayload) {
            $this->alreadyFailed = true;

            throw new ($this->exceptionClass)('Delivery of ' . $this->failingPayload . ' failed');
        }

        return $message;
    }
}
