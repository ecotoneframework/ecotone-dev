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
final class ChannelInterceptorRan extends AbstractChannelInterceptor
{
    public function __construct(private ChannelSendRecording $recording, private int $position)
    {
    }

    public function preSend(Message $message, MessageChannel $messageChannel): ?Message
    {
        $this->recording->interceptorRan($this->position);

        return $message;
    }

    public function afterSendCompletion(Message $message, MessageChannel $messageChannel, ?Throwable $exception): bool
    {
        $this->recording->completedAfter($this->position, $exception);

        return false;
    }
}
