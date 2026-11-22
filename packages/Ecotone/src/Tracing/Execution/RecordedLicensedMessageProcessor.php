<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\MessageProcessor;

/**
 * licence Apache-2.0
 */
final class RecordedLicensedMessageProcessor implements MessageProcessor
{
    /**
     * @param array<string, array{class: string, chosenBy: string}> $frameworkClassOf
     */
    public function __construct(private MessageProcessor $messageProcessor, private array $frameworkClassOf, private ExecutionRecordingTracer $tracer)
    {
    }

    public function process(Message $message): ?Message
    {
        return StepAround::of(tracer: $this->tracer, name: 'processor ' . ExecutionRecordingTracer::shortClassOf($this->frameworkClassOf[$this->messageProcessor::class]['class'] ?? $this->messageProcessor::class), call: fn () => $this->messageProcessor->process($message), hints: [ExecutionHint::shownInLines(ExecutionHint::CHOSEN_BY, $this->frameworkClassOf[$this->messageProcessor::class]['chosenBy'] ?? 'licence')]);
    }
}
