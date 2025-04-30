<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

class ProjectingManager
{
    public function __construct(
        private ProjectionStateStorage $projectionStateStorage,
        private ProjectorExecutor      $projectorExecutor,
        private StreamSource           $streamSource,
        private string                 $projectionName,
        private int                    $maxCount = 1000,
    ) {
    }

    // This is the method that is linked to the event bus routing channel
    public function execute(?string $partitionKey = null): void
    {
        $projectionState = $this->projectionStateStorage->getState($this->projectionName, $partitionKey);

        $streamPage = $this->streamSource->load($projectionState->lastPosition, $this->maxCount, $partitionKey);

        foreach ($streamPage->events as $event) {
            $this->projectorExecutor->project($event);
        }

        $this->projectionStateStorage->saveState($projectionState->withLastPosition($streamPage->lastPosition));
    }
}