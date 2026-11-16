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
     * @param array<string, string> $frameworkClassOf framework class of each class the licence may choose, keyed by the class that runs
     */
    public function __construct(private MessageProcessor $messageProcessor, private array $frameworkClassOf, private ExecutionRecordingTracer $tracer)
    {
    }

    public function process(Message $message): ?Message
    {
        return RecordedStep::of($this->tracer, ExecutionRecordingTracer::shortClassOf($this->frameworkClassOf[$this->messageProcessor::class] ?? $this->messageProcessor::class) . ', chosen by the licence', fn () => $this->messageProcessor->process($message));
    }
}
