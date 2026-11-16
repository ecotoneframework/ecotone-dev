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
    /**
     * @param array<string, string> $frameworkClassOf framework class of each class the licence may choose, keyed by the class that runs
     */
    public function __construct(private MessageHandler $messageHandler, private array $frameworkClassOf, private ExecutionRecordingTracer $tracer)
    {
    }

    public function handle(Message $message): void
    {
        RecordedStep::of($this->tracer, 'handler ' . ExecutionRecordingTracer::shortClassOf($this->frameworkClassOf[$this->messageHandler::class] ?? $this->messageHandler::class) . '::handle', fn () => $this->messageHandler->handle($message), hints: [ExecutionHint::critical(ExecutionHint::CHOSEN_BY, 'licence')]);
    }

    public function __toString(): string
    {
        return (string) $this->messageHandler;
    }
}
