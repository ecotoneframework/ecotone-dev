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
     * @param array<string, array{class: string, chosenBy: string}> $frameworkClassOf
     */
    public function __construct(private MessageHandler $messageHandler, private array $frameworkClassOf, private ExecutionRecordingTracer $tracer)
    {
    }

    public function handle(Message $message): void
    {
        StepAround::of(tracer: $this->tracer, name: 'handler ' . ExecutionRecordingTracer::shortClassOf($this->frameworkClassOf[$this->messageHandler::class]['class'] ?? $this->messageHandler::class) . '::handle', call: fn () => $this->messageHandler->handle($message), hints: [ExecutionHint::shownInLines(ExecutionHint::CHOSEN_BY, $this->frameworkClassOf[$this->messageHandler::class]['chosenBy'] ?? 'licence')]);
    }

    public function __toString(): string
    {
        return (string) $this->messageHandler;
    }
}
