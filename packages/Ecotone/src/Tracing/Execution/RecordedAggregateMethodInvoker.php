<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Modelling\ApplyMethod;
use Ecotone\Modelling\EventSourcingExecutor\AggregateMethodInvoker;
use Throwable;

/**
 * licence Apache-2.0
 */
final class RecordedAggregateMethodInvoker implements AggregateMethodInvoker
{
    public function __construct(private AggregateMethodInvoker $aggregateMethodInvoker, private ExecutionRecordingTracer $tracer)
    {
    }

    public function executeMethod(mixed $aggregate, ApplyMethod $applyMethod, Message $message): void
    {
        $step = $this->tracer->startStep('apply ' . ExecutionRecorder::typeOf($message->getPayload()) . ' to ' . ExecutionRecorder::typeOf($aggregate) . ', by ' . ExecutionRecordingTracer::shortClassOf($this->aggregateMethodInvoker::class));
        try {
            $this->aggregateMethodInvoker->executeMethod($aggregate, $applyMethod, $message);
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
}
