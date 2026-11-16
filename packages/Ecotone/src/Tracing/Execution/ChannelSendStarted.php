<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;
use Ecotone\Messaging\Channel\AbstractChannelInterceptor;
use Throwable;

/**
 * licence Apache-2.0
 */
final class ChannelSendStarted extends AbstractChannelInterceptor
{
    public function __construct(private ChannelSendRecording $recording)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $this->recording->started();

        return $message;
    }

    public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): bool
    {
        $this->recording->completed($exception);

        return false;
    }
}
