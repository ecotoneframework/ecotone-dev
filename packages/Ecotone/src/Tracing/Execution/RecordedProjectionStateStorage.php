<?php

declare(strict_types=1);

namespace Ecotone\Tracing\Execution;

use Ecotone\Projecting\ProjectionPartitionState;
use Ecotone\Projecting\ProjectionStateStorage;
use Ecotone\Projecting\Transaction;

/**
 * licence Apache-2.0
 */
final class RecordedProjectionStateStorage implements ProjectionStateStorage
{
    public function __construct(private ProjectionStateStorage $storage, private ExecutionRecordingTracer $tracer)
    {
    }

    public function canHandle(string $projectionName): bool
    {
        return $this->storage->canHandle($projectionName);
    }

    public function loadPartition(string $projectionName, ?string $partitionKey = null): ?ProjectionPartitionState
    {
        return RecordedStep::of(
            $this->tracer,
            'load partition ' . self::partitionOf($partitionKey) . ' of ' . $projectionName,
            fn () => $this->storage->loadPartition($projectionName, $partitionKey),
            fn (?ProjectionPartitionState $state): string => $state === null ? 'found nothing' : 'found it at position ' . ($state->lastPosition ?? 'start'),
        );
    }

    public function initPartition(string $projectionName, ?string $partitionKey = null): ?ProjectionPartitionState
    {
        return RecordedStep::of($this->tracer, 'init partition ' . self::partitionOf($partitionKey) . ' of ' . $projectionName, fn () => $this->storage->initPartition($projectionName, $partitionKey));
    }

    public function savePartition(ProjectionPartitionState $projectionState): void
    {
        RecordedStep::of($this->tracer, 'save partition ' . self::partitionOf($projectionState->partitionKey) . ' at position ' . ($projectionState->lastPosition ?? 'start') . ' of ' . $projectionState->projectionName, fn () => $this->storage->savePartition($projectionState));
    }

    public function delete(string $projectionName): void
    {
        RecordedStep::of($this->tracer, 'delete the state of ' . $projectionName, fn () => $this->storage->delete($projectionName));
    }

    public function init(string $projectionName): void
    {
        RecordedStep::of($this->tracer, 'init the state of ' . $projectionName, fn () => $this->storage->init($projectionName));
    }

    public function beginTransaction(): Transaction
    {
        return RecordedStep::of($this->tracer, 'begin the projection transaction', fn () => new RecordedTransaction($this->storage->beginTransaction(), $this->tracer));
    }

    private static function partitionOf(?string $partitionKey): string
    {
        return $partitionKey ?? '(the whole stream)';
    }
}
