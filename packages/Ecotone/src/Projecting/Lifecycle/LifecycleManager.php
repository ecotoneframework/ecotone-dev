<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Lifecycle;

use Ecotone\Projecting\ProjectionStateStorage;

class LifecycleManager
{
    /**
     * @param string[] $handledProjectionNames
     */
    public function __construct(
        private array $handledProjectionNames,
        private ProjectionStateStorage $projectionStateStorage,
        private ProjectionLifecycleStateStorage $projectionLifecycleStateStorage,
        private LifecycleExecutor $projectionLifecycleExecutor,
    ) {}

    public function init(string $projectionName): void
    {
        $this->assertProjectionNameIsHandled($projectionName);
        $hasBeenInit = $this->projectionLifecycleStateStorage->init($projectionName);

        if ($hasBeenInit) {
            $this->projectionLifecycleExecutor->init($projectionName);
        }
    }

    public function reset(string $projectionName): void
    {
        $this->assertProjectionNameIsHandled($projectionName);
        $this->projectionStateStorage->deleteState($projectionName);
        $this->projectionLifecycleExecutor->reset($projectionName);
    }

    public function delete(string $projectionName): void
    {
        $this->assertProjectionNameIsHandled($projectionName);
        $hasBeenDeleted = $this->projectionLifecycleStateStorage->delete($projectionName);

        $this->projectionStateStorage->deleteState($projectionName);
        if ($hasBeenDeleted) {
            $this->projectionLifecycleExecutor->delete($projectionName);
        }
    }

    private function assertProjectionNameIsHandled(string $projectionName)
    {
        if (!\in_array($projectionName, $this->handledProjectionNames, true)) {
            throw new \InvalidArgumentException(
                \sprintf('Projection with name "%s" does not exist', $projectionName)
            );
        }
    }
}