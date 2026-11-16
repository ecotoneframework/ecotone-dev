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
            $this->tracer->endStep($step, $this->outcomeOf($result));
        }

        return $result;
    }

    private function outcomeOf(?Message $result): string
    {
        return match ($this->step) {
            self::IDENTIFIER => $result !== null && $result->getHeaders()->containsKey(AggregateMessage::AGGREGATE_ID)
                ? 'to ' . json_encode(AggregateIdMetadata::createFrom($result->getHeaders()->get(AggregateMessage::AGGREGATE_ID))->getIdentifiers(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : 'to no identifier',
            self::LOAD => match (true) {
                $result === null => 'dropped the message, as no aggregate was found',
                $result->getHeaders()->containsKey(AggregateMessage::CALLED_AGGREGATE_INSTANCE) => 'found it',
                default => 'found nothing, so the factory runs',
            },
            default => '',
        };
    }
}
