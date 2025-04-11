<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Ecotone\Modelling\Event;

class ProjectingManager
{
    public function __construct(
        private ProjectionStateStorage $projectionStateStorage,
        private ProjectorExecutor      $projectionExecutor,
        private StreamSource           $streamSource,
        private string                 $streamName,
    ) {
    }

    public function execute(string $projectionName, string $partitionKey): void
    {
        $projectionState = $this->projectionStateStorage->getState($projectionName, $partitionKey);

        $streamPage = $this->streamSource->load($this->streamName, $projectionState->lastPosition, 100);

        foreach ($streamPage->events as $event) {
            $this->projectionExecutor->project($event);
        }

        $projectionState->withLastPosition($streamPage->lastPosition);

    }
}