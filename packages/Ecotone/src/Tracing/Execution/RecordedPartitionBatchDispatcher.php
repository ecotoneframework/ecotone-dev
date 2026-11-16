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
        RecordedStep::of(
            $this->tracer,
            'dispatch ' . count($batches) . ' partition batches of ' . $operation . ' to ' . ($asyncChannelName ?? 'this process') . ', by ' . ExecutionRecordingTracer::shortClassOf($this->partitionBatchDispatcher::class),
            fn () => $this->partitionBatchDispatcher->dispatch($projectionName, $operation, $asyncChannelName, $prepare, $batches),
        );
    }
}
