<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Api\Messaging\MessageChannel;

/**
 * licence Apache-2.0
 */
final class RecordedReplyChannel implements MessageChannel
{
    public function __construct(private MessageChannel $messageChannel, private string $destination, private ReplyRecording $recording)
    {
    }

    public function send(Message $message): void
    {
        $this->recording->routedTo($this->destination, fn () => $this->messageChannel->send($message));
    }

    public function __toString(): string
    {
        return (string) $this->messageChannel;
    }
}
