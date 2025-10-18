<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use InvalidArgumentException;
use Throwable;

class ProjectingManager
{
    public function __construct(
        private ProjectionStateStorage $projectionStateStorage,
        private ProjectorExecutor      $projectorExecutor,
        private StreamSource           $streamSource,
        private PartitionProvider      $partitionProvider,
        private string                 $projectionName,
        private int                    $batchSize = 1000,
        private ProjectionInitializationMode $initializationMode = ProjectionInitializationMode::AUTO,
    ) {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Batch size must be at least 1');
        }
    }

    // This is the method that is linked to the event bus routing channel
    public function execute(?string $partitionKey = null, bool $force = false): void
    {
        do {
            $transaction = $this->projectionStateStorage->beginTransaction();
            try {
                $projectionState = $this->projectionStateStorage->loadPartition($this->projectionName, $partitionKey);

                // Check if projection is initialized
                if (! $projectionState) {
                    // Projection not initialized yet
                    if ($force || $this->initializationMode === ProjectionInitializationMode::AUTO) {
                        // Manual trigger or event trigger with auto mode - initialize and run
                        $projectionState = $this->projectionStateStorage->initPartition($this->projectionName, $partitionKey);
                        if ($projectionState) {
                            $this->projectorExecutor->init();
                        } else {
                            // Someone else initialized it in the meantime, reload the state
                            $projectionState = $this->projectionStateStorage->loadPartition($this->projectionName, $partitionKey);
                        }
                    } else {
                        // Event trigger with skip mode - skip execution
                        $transaction->commit();
                        return;
                    }
                }
                
                if (!$force && $projectionState->status === ProjectionStatus::DISABLED) {
                    // Skip execution if disabled
                    $transaction->commit();
                    return;
                }
                $streamPage = $this->streamSource->load($projectionState->lastPosition, $this->batchSize, $partitionKey);

                $userState = $projectionState->userState;
                foreach ($streamPage->events as $event) {
                    $userState = $this->projectorExecutor->project($event, $userState);
                }
                $projectionState = $projectionState
                        ->withLastPosition($streamPage->lastPosition)
                        ->withUserState($userState);

                if (count($streamPage->events) === 0 && $force) {
                    // If we are forcing execution and there are no new events, we still want to enable the projection if it was uninitialized
                    $projectionState = $projectionState->withStatus(ProjectionStatus::ENABLED);
                }

                $this->projectionStateStorage->savePartition($projectionState);
                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollBack();
                throw $e;
            }
        } while (count($streamPage->events) > 0); // TODO: we should handle the transaction lifecycle here or ignore batch size
    }

    public function loadState(?string $partitionKey = null): ProjectionPartitionState
    {
        return $this->projectionStateStorage->loadPartition($this->projectionName, $partitionKey);
    }

    public function init(): void
    {
        $this->projectionStateStorage->init($this->projectionName);

        $this->projectorExecutor->init();
    }

    public function delete(): void
    {
        $this->projectionStateStorage->delete($this->projectionName);

        $this->projectorExecutor->delete();
    }

    public function backfill(): void
    {
        foreach ($this->partitionProvider->partitions() as $partition) {
            $this->execute($partition, true);
        }
    }
}
