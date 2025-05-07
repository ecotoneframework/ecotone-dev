<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Lifecycle;

use Ecotone\Projecting\ProjectionStateStorage;

class LifecycleManager
{
    public function __construct(
        private ProjectionStateStorage $projectionStateStorage,
        private ProjectionLifecycleStateStorage $projectionLifecycleStateStorage,
        private LifecycleExecutor $projectionLifecycleExecutor,
    ) {}

    public function init(string $projectionName): void
    {
        $hasBeenInit = $this->projectionLifecycleStateStorage->init($projectionName);

        if ($hasBeenInit) {
            $this->projectionLifecycleExecutor->init($projectionName);
        }
    }

    public function reset(string $projectionName): void
    {
        $this->projectionStateStorage->deleteState($projectionName);
        $this->projectionLifecycleExecutor->reset($projectionName);
    }

    public function delete(string $projectionName): void
    {
        $hasBeenDeleted = $this->projectionLifecycleStateStorage->delete($projectionName);

        $this->projectionStateStorage->deleteState($projectionName);
        if ($hasBeenDeleted) {
            $this->projectionLifecycleExecutor->delete($projectionName);
        }
    }
}