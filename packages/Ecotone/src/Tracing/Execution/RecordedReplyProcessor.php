<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\MessageProcessor;

/**
 * licence Apache-2.0
 */
final class RecordedReplyProcessor implements MessageProcessor
{
    public function __construct(private MessageProcessor $messageProcessor, private ReplyRecording $recording)
    {
    }

    public function process(Message $message): ?Message
    {
        $reply = $this->messageProcessor->process($message);
        $this->recording->replied($reply);

        return $reply;
    }
}
