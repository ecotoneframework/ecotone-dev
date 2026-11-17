<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Messaging\Handler\MessageProcessor;
use Ecotone\Modelling\AggregateFlow\AggregateIdMetadata;
use Ecotone\Modelling\AggregateMessage;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedAggregateStep implements MessageProcessor
{
    public const IDENTIFIER = 'resolve the aggregate identifier';
    public const LOAD = 'load the aggregate';
    public const SAVE = 'save the aggregate';

    public function __construct(private MessageProcessor $messageProcessor, private string $step, private ExecutionRecordingTracer $tracer)
    {
    }

    public function process(Message $message): ?Message
    {
        $step = $this->tracer->startStep($this->step);
        try {
            $result = $this->messageProcessor->process($message);
        } catch (Throwable $failure) {
            if ($step !== null) {
                $this->tracer->failStep($step, $failure);
            }

            throw $failure;
        }
        if ($step !== null) {
            $this->tracer->endStep($step, '', $this->outcomeOf($result));
        }

        return $result;
    }

    /**
     * @return list<ExecutionHint>
     */
    private function outcomeOf(?Message $result): array
    {
        return match ($this->step) {
            self::IDENTIFIER => [ExecutionHint::detail(ExecutionHint::IDENTIFIERS, $result !== null && $result->getHeaders()->containsKey(AggregateMessage::AGGREGATE_ID)
                ? ExecutionHint::json(AggregateIdMetadata::createFrom($result->getHeaders()->get(AggregateMessage::AGGREGATE_ID))->getIdentifiers())
                : 'no identifier')],
            self::LOAD => [match (true) {
                $result === null => ExecutionHint::critical(ExecutionHint::FOUND, 'nothing, message dropped'),
                $result->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_INSTANCE) => ExecutionHint::detail(ExecutionHint::FOUND, 'the aggregate'),
                default => ExecutionHint::critical(ExecutionHint::FOUND, 'nothing, the factory runs'),
            }],
            default => [],
        };
    }
}
