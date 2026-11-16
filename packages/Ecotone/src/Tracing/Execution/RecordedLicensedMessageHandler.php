<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\MessageHandler;

/**
 * licence Apache-2.0
 */
final class RecordedLicensedMessageHandler implements MessageHandler
{
    public function __construct(private MessageHandler $messageHandler, private ExecutionRecordingTracer $tracer)
    {
    }

    public function handle(Message $message): void
    {
        RecordedStep::of($this->tracer, ExecutionRecordingTracer::shortClassOf($this->messageHandler::class) . ', chosen by the licence', fn () => $this->messageHandler->handle($message));
    }

    public function __toString(): string
    {
        return (string) $this->messageHandler;
    }
}
