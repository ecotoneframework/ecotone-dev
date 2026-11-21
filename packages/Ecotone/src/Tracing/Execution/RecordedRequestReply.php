<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedRequestReply implements MessageHandler
{
    public function __construct(private MessageHandler $requestReplyProducer, private ReplyRecording $recording)
    {
    }

    public function handle(Message $message): void
    {
        $this->recording->started($message);
        try {
            $this->requestReplyProducer->handle($message);
        } catch (Throwable $failure) {
            $this->recording->finished($message, true);

            throw $failure;
        }
        $this->recording->finished($message, false);
    }

    public function __toString(): string
    {
        return (string) $this->requestReplyProducer;
    }
}
