<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Api\Messaging\Message;
use Ecotone\Projecting\PartitionBatchDispatcher;

/**
 * licence Apache-2.0
 */
final class RecordedPartitionBatchDispatcher implements PartitionBatchDispatcher
{
    public function __construct(private PartitionBatchDispatcher $partitionBatchDispatcher, private ExecutionRecordingTracer $tracer)
    {
    }

    public function dispatch(string $projectionName, string $operation, ?string $asyncChannelName, Message $prepare, array $batches): void
    {
        StepAround::of(
            tracer: $this->tracer,
            name: 'dispatch ' . count($batches) . ' partition batches to ' . ($asyncChannelName ?? 'this process'),
            call: fn () => $this->partitionBatchDispatcher->dispatch(projectionName: $projectionName, operation: $operation, asyncChannelName: $asyncChannelName, prepare: $prepare, batches: $batches),
            hints: [ExecutionHint::shownInLines(ExecutionHint::SERVICE, ExecutionRecordingTracer::shortClassOf($this->partitionBatchDispatcher::class) . ', for ' . $operation)],
        );
    }
}
