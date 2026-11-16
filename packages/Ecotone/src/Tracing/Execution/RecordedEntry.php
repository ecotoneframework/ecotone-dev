<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\MessageProcessor;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedEntry implements MessageProcessor
{
    public function __construct(private MessageProcessor $messageProcessor, private string $entry, private ExecutionRecordingTracer $tracer)
    {
    }

    public function process(Message $message): ?Message
    {
        $step = $this->tracer->startEntry($this->entry);
        try {
            $reply = $this->messageProcessor->process($message);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, ExecutionRecorder::outcomeOf($reply));
        }

        return $reply;
    }
}
