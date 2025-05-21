<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Projecting\ProjectionState;
use Ecotone\Projecting\ProjectionStateStorage;

class InMemoryProjectionStateStorage implements ProjectionStateStorage
{
    private array $projectionStates = [];

    public function getState(string $projectionName, ?string $partitionKey = null, bool $lock = true): ProjectionState
    {
        $key = $this->getKey($projectionName, $partitionKey);
        return $this->projectionStates[$key] ?? new ProjectionState($projectionName, $partitionKey);
    }

    public function saveState(ProjectionState $projectionState): void
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

    public function deleteState(string $projectionName): void
    {
        $projectionStartKey = $this->getKey($projectionName, "");
        foreach ($this->projectionStates as $key => $value) {
            if (str_starts_with($key, $projectionStartKey)) {
                unset($this->projectionStates[$key]);
            }
        }
    }
}