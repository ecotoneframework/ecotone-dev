<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Projecting\NoOpTransaction;
use Ecotone\Projecting\ProjectionInitializationStatus;
use Ecotone\Projecting\ProjectionPartitionState;
use Ecotone\Projecting\ProjectionStateStorage;
use Ecotone\Projecting\Transaction;

use function in_array;

class InMemoryProjectionStateStorage implements ProjectionStateStorage
{
    /**
     * @var array<string, ProjectionPartitionState> key is projection name
     */
    private array $projectionStates = [];

    /**
     * @param string[]|null $projectionNames
     */
    public function __construct(
        private ?array $projectionNames = null,
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return $this->projectionNames === null || in_array($projectionName, $this->projectionNames, true);
    }

    public function loadPartition(string $projectionName, ?string $partitionKey, string $streamName, bool $lock = true): ?ProjectionPartitionState
    {
        $key = $this->getKey($projectionName, $partitionKey, $streamName);
        return $this->projectionStates[$key] ?? null;
    }

    public function initPartition(string $projectionName, ?string $partitionKey, string $streamName): ?ProjectionPartitionState
    {
        $key = $this->getKey($projectionName, $partitionKey, $streamName);

        if (! isset($this->projectionStates[$key])) {
            $this->projectionStates[$key] = new ProjectionPartitionState($projectionName, $partitionKey, $streamName, null, null, ProjectionInitializationStatus::UNINITIALIZED);
            return $this->projectionStates[$key];
        }

        return null; // Already exists
    }

    public function savePartition(ProjectionPartitionState $projectionState): void
    {
        $key = $this->getKey($projectionState->projectionName, $projectionState->partitionKey, $projectionState->streamName);
        $this->projectionStates[$key] = $projectionState;
    }

    private function getKey(string $projectionName, ?string $partitionKey, string $streamName): string
    {
        $key = $projectionName;
        if ($streamName !== '') {
            $key .= '::' . $streamName;
        }
        if ($partitionKey !== null) {
            $key .= '-' . $partitionKey;
        }
        return $key;
    }

    public function delete(string $projectionName): void
    {
        $projectionStartKey = $projectionName;
        foreach ($this->projectionStates as $key => $value) {
            if (str_starts_with($key, $projectionStartKey)) {
                unset($this->projectionStates[$key]);
            }
        }
    }

    public function init(string $projectionName): void
    {
    }

    public function beginTransaction(): Transaction
    {
        return new NoOpTransaction();
    }
}
