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
        return StepAround::of(
            tracer: $this->tracer,
            name: 'load partition ' . self::partitionOf($partitionKey) . ' of ' . $projectionName,
            call: fn () => $this->storage->loadPartition($projectionName, $partitionKey),
            outcome: fn (?ProjectionPartitionState $state): ExecutionHint => $state === null ? ExecutionHint::shownInLines(ExecutionHint::FOUND, 'nothing') : ExecutionHint::detail(ExecutionHint::FOUND, 'position ' . ($state->lastPosition ?? 'start')),
        );
    }

    public function initPartition(string $projectionName, ?string $partitionKey = null): ?ProjectionPartitionState
    {
        return StepAround::of(tracer: $this->tracer, name: 'init partition ' . self::partitionOf($partitionKey) . ' of ' . $projectionName, call: fn () => $this->storage->initPartition($projectionName, $partitionKey));
    }

    public function savePartition(ProjectionPartitionState $projectionState): void
    {
        StepAround::of(tracer: $this->tracer, name: 'save partition ' . self::partitionOf($projectionState->partitionKey) . ' at position ' . ($projectionState->lastPosition ?? 'start') . ' of ' . $projectionState->projectionName, call: fn () => $this->storage->savePartition($projectionState));
    }

    public function delete(string $projectionName): void
    {
        StepAround::of(tracer: $this->tracer, name: 'delete the state of ' . $projectionName, call: fn () => $this->storage->delete($projectionName));
    }

    public function init(string $projectionName): void
    {
        StepAround::of(tracer: $this->tracer, name: 'init the state of ' . $projectionName, call: fn () => $this->storage->init($projectionName));
    }

    public function beginTransaction(): Transaction
    {
        return StepAround::of(tracer: $this->tracer, name: 'begin the projection transaction', call: fn () => new RecordedTransaction($this->storage->beginTransaction(), $this->tracer));
    }

    private static function partitionOf(?string $partitionKey): string
    {
        return $partitionKey ?? '(the whole stream)';
    }
}
