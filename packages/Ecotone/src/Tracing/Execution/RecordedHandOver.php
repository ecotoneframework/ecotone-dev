<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\MessageHandler;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedHandOver implements MessageHandler
{
    public function __construct(
        private string $handOver,
        private MessageHandler $messageHandler,
        private ExecutionRecordingTracer $tracer,
    ) {
    }

    public function handle(Message $message): void
    {
        $step = $this->tracer->startEntry($this->handOver);
        try {
            $this->messageHandler->handle($message);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '');
        }
    }

    public function __toString(): string
    {
        return (string) $this->messageHandler;
    }
}
