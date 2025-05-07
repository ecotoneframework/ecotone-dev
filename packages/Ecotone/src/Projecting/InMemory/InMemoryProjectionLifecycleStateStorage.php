<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\InMemory;

use Ecotone\Projecting\Lifecycle\ProjectionLifecycleStateStorage;

class InMemoryProjectionLifecycleStateStorage implements ProjectionLifecycleStateStorage
{
    /**
     * @var array<string, true> key is projection name
     */
    private array $projectionLifecycleState = [];

    public function init(string $projectionName): bool
    {
        if (isset($this->projectionLifecycleState[$projectionName])) {
            return false;
        }

        $this->projectionLifecycleState[$projectionName] = true;

        return true;
    }

    public function delete(string $projectionName): bool
    {
        if (!isset($this->projectionLifecycleState[$projectionName])) {
            return false;
        }

        unset($this->projectionLifecycleState[$projectionName]);

        return true;
    }
}