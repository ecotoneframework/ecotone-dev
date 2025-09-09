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
        private int                    $batchSize = 1000,
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('Batch size must be at least 1');
        }
    }

    // This is the method that is linked to the event bus routing channel
    public function execute(?string $partitionKey = null): void
    {
        $this->init();

        do {
            $projectionState = $this->projectionStateStorage->loadPartition($this->projectionName, $partitionKey);

            $streamPage = $this->streamSource->load($projectionState->lastPosition, $this->batchSize, $partitionKey);

            $userState = $projectionState->userState;
            foreach ($streamPage->events as $event) {
                $userState = $this->projectorExecutor->project($event, $userState);
            }

            $this->projectionStateStorage->savePartition(
                $projectionState
                    ->withLastPosition($streamPage->lastPosition)
                    ->withUserState($userState)
            );
        } while (count($streamPage->events) > 0); // TODO: we should handle the transaction lifecycle here or ignore batch size
    }

    public function init(): void
    {
        $hasBeenInit = $this->projectionStateStorage->init($this->projectionName);

        if ($hasBeenInit) {
            $this->projectorExecutor->init();
        }
    }

    public function delete(): void
    {
        $hasBeenDeleted = $this->projectionStateStorage->delete($this->projectionName);

        if ($hasBeenDeleted) {
            $this->projectorExecutor->delete();
        }
    }

    public function backfill(PartitionProvider $partitionProvider): void
    {
        foreach ($partitionProvider->partitions() as $partition) {
            $this->execute($partition);
        }
    }
}