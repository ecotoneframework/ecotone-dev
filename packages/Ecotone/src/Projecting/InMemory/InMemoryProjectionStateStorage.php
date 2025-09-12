<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Projecting\NoOpTransaction;
use Ecotone\Projecting\ProjectionPartitionState;
use Ecotone\Projecting\ProjectionStateStorage;
use Ecotone\Projecting\Transaction;

class InMemoryProjectionStateStorage implements ProjectionStateStorage
{
    /**
     * @var array<string, true> key is projection name
     */
    private array $projectionLifecycleState = [];
    /**
     * @var array<string, ProjectionPartitionState> key is projection name
     */
    private array $projectionStates = [];

    public function loadPartition(string $projectionName, ?string $partitionKey = null, bool $lock = true): ProjectionPartitionState
    {
        $key = $this->getKey($projectionName, $partitionKey);
        return $this->projectionStates[$key] ?? new ProjectionPartitionState($projectionName, $partitionKey);
    }

    public function savePartition(ProjectionPartitionState $projectionState): void
    {
        $key = $this->getKey($projectionState->projectionName, $projectionState->partitionKey);
        $this->projectionStates[$key] = $projectionState;
    }

    private function getKey(string $projectionName, ?string $partitionKey): string
    {
        if ($partitionKey === null) {
            return $projectionName;
        }
        return $projectionName . '-' . $partitionKey;
    }

    public function delete(string $projectionName): bool
    {
        if (!isset($this->projectionLifecycleState[$projectionName])) {
            return false;
        }

        $projectionStartKey = $this->getKey($projectionName, null);
        foreach ($this->projectionStates as $key => $value) {
            if (str_starts_with($key, $projectionStartKey)) {
                unset($this->projectionStates[$key]);
            }
        }
        unset($this->projectionLifecycleState[$projectionName]);
        return true;
    }

    public function init(string $projectionName): bool
    {
        if (isset($this->projectionLifecycleState[$projectionName])) {
            return false;
        }

        $this->projectionLifecycleState[$projectionName] = true;

        return true;
    }

    public function beginTransaction(): Transaction
    {
        return new NoOpTransaction();
    }
}