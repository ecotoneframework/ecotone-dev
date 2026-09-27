<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting;

use Ecotone\Messaging\Endpoint\Interceptor\TerminationListener;
use Ecotone\Messaging\Gateway\MessagingEntrypointService;
use InvalidArgumentException;
use Throwable;

class ProjectingManager
{
    private const DEFAULT_BACKFILL_PARTITION_BATCH_SIZE = 100;
    private const DEFAULT_REBUILD_PARTITION_BATCH_SIZE = 100;

    private ?ProjectionStateStorage $projectionStateStorage = null;

    public function __construct(
        private ProjectionStateStorageRegistry $projectionStateStorageRegistry,
        private ProjectorExecutor              $projectorExecutor,
        private StreamSourceRegistry           $streamSourceRegistry,
        private PartitionProviderRegistry      $partitionProviderRegistry,
        private StreamFilterRegistry           $streamFilterRegistry,
        private string                         $projectionName,
        private TerminationListener            $terminationListener,
        private MessagingEntrypointService      $messagingEntrypoint,
        private int                            $eventLoadingBatchSize = 1000,
        private bool                           $automaticInitialization = true,
        private int                            $backfillPartitionBatchSize = self::DEFAULT_BACKFILL_PARTITION_BATCH_SIZE,
        private ?string                        $backfillAsyncChannelName = null,
        private int                            $rebuildPartitionBatchSize = self::DEFAULT_REBUILD_PARTITION_BATCH_SIZE,
        private ?string                        $rebuildAsyncChannelName = null,
        private bool                           $processStreamsInParallel = false,
    ) {
        if ($eventLoadingBatchSize < 1) {
            throw new InvalidArgumentException('Event loading batch size must be at least 1');
        }
        if ($backfillPartitionBatchSize < 1) {
            throw new InvalidArgumentException('Backfill partition batch size must be at least 1');
        }
        if ($rebuildPartitionBatchSize < 1) {
            throw new InvalidArgumentException('Rebuild partition batch size must be at least 1');
        }
    }

    private function getProjectionStateStorage(): ProjectionStateStorage
    {
        if ($this->projectionStateStorage === null) {
            $this->projectionStateStorage = $this->projectionStateStorageRegistry->getFor($this->projectionName);
        }
        return $this->projectionStateStorage;
    }

    public function execute(?string $partitionKeyValue = null, bool $manualInitialization = false, ?string $streamName = null): void
    {
        do {
            $processedEvents = $this->messagingEntrypoint->sendWithHeaders(
                [],
                [
                    ProjectingHeaders::PROJECTION_PARTITION_KEY => $partitionKeyValue,
                    ProjectingHeaders::PROJECTION_CAN_INITIALIZE => $manualInitialization || $this->automaticInitialization,
                    ProjectingHeaders::PROJECTION_STREAM_NAME => $streamName,
                ],
                self::batchChannelFor($this->projectionName)
            );
        } while ($processedEvents > 0 && $this->terminationListener->shouldTerminate() !== true);
    }

    public function executePartitionBatch(?string $partitionKeyValue = null, bool $canInitialize = false, bool $shouldReset = false, ?string $streamName = null, bool $replayStream = false): int
    {
        $streamSource = $this->streamSourceRegistry->getFor($this->projectionName);
        if ($partitionKeyValue === null && $streamSource instanceof PerStreamSource) {
            return $this->processStreamsInParallel
                ? $this->executeStreamsInParallel($streamSource, $canInitialize, $shouldReset, $streamName, $replayStream)
                : $this->executeStreamsSequentially($streamSource, $canInitialize, $shouldReset);
        }

        $transaction = $this->getProjectionStateStorage()->beginTransaction();
        try {
            $projectionState = $this->loadOrInitializePartitionState($partitionKeyValue, $canInitialize);
            if ($projectionState === null) {
                $transaction->commit();
                return 0;
            }

            if ($shouldReset) {
                $this->projectorExecutor->reset($partitionKeyValue);
                $projectionState = new ProjectionPartitionState(
                    $projectionState->projectionName,
                    $projectionState->partitionKey,
                    null,
                    null,
                    $projectionState->status,
                );
            }

            $totalProcessedEvents = 0;
            $userState = $projectionState->userState;

            do {
                $streamPage = $streamSource->load($this->projectionName, $projectionState->lastPosition, $this->eventLoadingBatchSize, $partitionKeyValue);

                $batchProcessedEvents = 0;
                foreach ($streamPage->events as $event) {
                    $userState = $this->projectorExecutor->project($event, $userState, $shouldReset);
                    $batchProcessedEvents++;
                }
                if ($batchProcessedEvents > 0) {
                    $this->projectorExecutor->flush($userState, $shouldReset);
                }

                $totalProcessedEvents += $batchProcessedEvents;
                $projectionState = $projectionState
                    ->withLastPosition($streamPage->lastPosition)
                    ->withUserState($userState);
            } while ($shouldReset && $batchProcessedEvents >= $this->eventLoadingBatchSize);

            if ($totalProcessedEvents === 0 && $canInitialize) {
                $projectionState = $projectionState->withStatus(ProjectionInitializationStatus::INITIALIZED);
            }

            $this->getProjectionStateStorage()->savePartition($projectionState);
            $transaction->commit();
            return $totalProcessedEvents;
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    public static function batchChannelFor(string $projectionName): string
    {
        return 'projecting_manager_batch:' . $projectionName;
    }

    public function loadState(?string $partitionKey = null): ProjectionPartitionState
    {
        return $this->getProjectionStateStorage()->loadPartition($this->projectionName, $partitionKey);
    }

    public function getPartitionProvider(): PartitionProvider
    {
        return $this->partitionProviderRegistry->getPartitionProviderFor($this->projectionName);
    }

    public function getProjectionName(): string
    {
        return $this->projectionName;
    }

    public function init(): void
    {
        $this->getProjectionStateStorage()->init($this->projectionName);

        $this->projectorExecutor->init();
    }

    public function delete(): void
    {
        $this->getProjectionStateStorage()->delete($this->projectionName);

        $this->projectorExecutor->delete();
    }

    public function prepareBackfill(): void
    {
        $this->preparePartitionBatches($this->backfillPartitionBatchSize, $this->backfillAsyncChannelName, false);
    }

    /**
     * @deprecated Use prepareBackfill() instead. This method is kept for backward compatibility.
     */
    public function backfill(): void
    {
        $this->prepareBackfill();
    }

    public function executeWithReset(?string $partitionKeyValue = null): void
    {
        $this->messagingEntrypoint->sendWithHeaders(
            [],
            [
                ProjectingHeaders::PROJECTION_PARTITION_KEY => $partitionKeyValue,
                ProjectingHeaders::PROJECTION_CAN_INITIALIZE => true,
                ProjectingHeaders::PROJECTION_SHOULD_RESET => true,
            ],
            self::batchChannelFor($this->projectionName)
        );
    }

    public function replayStream(string $streamName): void
    {
        $this->messagingEntrypoint->sendWithHeaders(
            [],
            [
                ProjectingHeaders::PROJECTION_PARTITION_KEY => null,
                ProjectingHeaders::PROJECTION_CAN_INITIALIZE => true,
                ProjectingHeaders::PROJECTION_STREAM_NAME => $streamName,
                ProjectingHeaders::PROJECTION_REPLAY_STREAM => true,
            ],
            self::batchChannelFor($this->projectionName)
        );
    }

    public function executeStreamBatch(?string $streamName, bool $shouldReset, bool $replayStream): void
    {
        match (true) {
            $replayStream && $streamName !== null => $this->replayStream($streamName),
            $shouldReset => $this->executeWithReset(),
            default => $this->execute(null, true, $streamName),
        };
    }

    public function prepareRebuild(): void
    {
        $this->preparePartitionBatches($this->rebuildPartitionBatchSize, $this->rebuildAsyncChannelName, true);
    }

    private function preparePartitionBatches(int $partitionBatchSize, ?string $asyncChannelName, bool $shouldReset): void
    {
        if ($this->isTrackedPerStream()) {
            $this->prepareStreamBatches($asyncChannelName, $shouldReset);
            return;
        }

        $streamFilters = $this->streamFilterRegistry->provide($this->projectionName);

        foreach ($streamFilters as $streamFilter) {
            $totalPartitions = $this->getPartitionProvider()->count($streamFilter);

            if ($totalPartitions === 0) {
                continue;
            }

            $numberOfBatches = (int) ceil($totalPartitions / $partitionBatchSize);

            for ($batch = 0; $batch < $numberOfBatches; $batch++) {
                $offset = $batch * $partitionBatchSize;

                $headers = [
                    'partitionBatch.limit' => $partitionBatchSize,
                    'partitionBatch.offset' => $offset,
                    'partitionBatch.streamName' => $streamFilter->streamName,
                    'partitionBatch.aggregateType' => $streamFilter->aggregateType,
                    'partitionBatch.eventStoreReferenceName' => $streamFilter->eventStoreReferenceName,
                    'partitionBatch.shouldReset' => $shouldReset,
                ];

                $this->sendPartitionBatchMessage($headers, $asyncChannelName);
            }
        }
    }

    private function isTrackedPerStream(): bool
    {
        return $this->getPartitionProvider() instanceof SinglePartitionProvider
            && $this->streamSourceRegistry->getFor($this->projectionName) instanceof PerStreamSource;
    }

    private function prepareStreamBatches(?string $asyncChannelName, bool $shouldReset): void
    {
        if (! $this->processStreamsInParallel) {
            $this->sendPartitionBatchMessage([
                'partitionBatch.tracksStreams' => true,
                'partitionBatch.shouldReset' => $shouldReset,
            ], $asyncChannelName);
            return;
        }

        if ($shouldReset) {
            $this->resetAllStreams();
        }

        foreach ($this->uniqueStreamFilters() as $streamFilter) {
            $this->sendPartitionBatchMessage([
                'partitionBatch.tracksStreams' => true,
                'partitionBatch.streamName' => $streamFilter->streamName,
                'partitionBatch.replayStream' => $shouldReset,
            ], $asyncChannelName);
        }
    }

    private function executeStreamsSequentially(PerStreamSource $streamSource, bool $canInitialize, bool $shouldReset): int
    {
        $transaction = $this->getProjectionStateStorage()->beginTransaction();
        try {
            $projectionState = $this->loadOrInitializePartitionState(null, $canInitialize);
            if ($projectionState === null) {
                $transaction->commit();
                return 0;
            }
            $projectionState = $this->moveCombinedPositionIntoStreamPositions($streamSource, $projectionState);

            if ($shouldReset) {
                $this->projectorExecutor->reset(null);
                $projectionState = $projectionState->withUserState(null);
            }

            $userState = $projectionState->userState;
            $totalProcessedEvents = 0;
            foreach ($this->uniqueStreamFilters() as $streamFilter) {
                $streamState = $this->loadOrCreateStreamState($streamFilter->streamName);
                [$lastPosition, $userState, $processedEvents] = $this->projectStream(
                    $streamSource,
                    $streamFilter,
                    $shouldReset ? null : $streamState->lastPosition,
                    $userState,
                    $shouldReset,
                );
                $this->getProjectionStateStorage()->savePartition($streamState->withLastPosition($lastPosition));
                $totalProcessedEvents += $processedEvents;
            }

            $projectionState = $projectionState->withUserState($userState);
            if ($totalProcessedEvents === 0 && $canInitialize) {
                $projectionState = $projectionState->withStatus(ProjectionInitializationStatus::INITIALIZED);
            }

            $this->getProjectionStateStorage()->savePartition($projectionState);
            $transaction->commit();
            return $totalProcessedEvents;
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function executeStreamsInParallel(PerStreamSource $streamSource, bool $canInitialize, bool $shouldReset, ?string $onlyStreamName, bool $replayStream): int
    {
        $projectionState = $this->prepareProjectionForParallelStreams($streamSource, $canInitialize);
        if ($projectionState === null) {
            return 0;
        }

        if ($shouldReset) {
            $this->resetAllStreams();
        }

        $totalProcessedEvents = 0;
        foreach ($this->uniqueStreamFilters() as $streamFilter) {
            if ($onlyStreamName !== null && $streamFilter->streamName !== $onlyStreamName) {
                continue;
            }
            $totalProcessedEvents += $this->executeStreamInItsOwnTransaction($streamSource, $streamFilter, $shouldReset || $replayStream);
        }

        if ($totalProcessedEvents === 0 && $canInitialize && $projectionState->status !== ProjectionInitializationStatus::INITIALIZED) {
            $this->markProjectionAsInitialized();
        }

        return $totalProcessedEvents;
    }

    private function prepareProjectionForParallelStreams(PerStreamSource $streamSource, bool $canInitialize): ?ProjectionPartitionState
    {
        $transaction = $this->getProjectionStateStorage()->beginTransaction();
        try {
            $projectionState = $this->loadOrInitializePartitionState(null, $canInitialize);
            if ($projectionState !== null) {
                $projectionState = $this->moveCombinedPositionIntoStreamPositions($streamSource, $projectionState);
            }
            $transaction->commit();
            return $projectionState;
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function executeStreamInItsOwnTransaction(PerStreamSource $streamSource, StreamFilter $streamFilter, bool $isRebuilding): int
    {
        $transaction = $this->getProjectionStateStorage()->beginTransaction();
        try {
            $streamState = $this->loadOrCreateStreamState($streamFilter->streamName);
            [$lastPosition, $userState, $processedEvents] = $this->projectStream(
                $streamSource,
                $streamFilter,
                $streamState->lastPosition,
                $streamState->userState,
                $isRebuilding,
            );
            $this->getProjectionStateStorage()->savePartition(
                $streamState->withLastPosition($lastPosition)->withUserState($userState)
            );
            $transaction->commit();
            return $processedEvents;
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function resetAllStreams(): void
    {
        $transaction = $this->getProjectionStateStorage()->beginTransaction();
        try {
            $projectionState = $this->loadOrInitializePartitionState(null, true);
            $streamSource = $this->streamSourceRegistry->getFor($this->projectionName);
            if ($projectionState !== null && $streamSource instanceof PerStreamSource) {
                $this->moveCombinedPositionIntoStreamPositions($streamSource, $projectionState);
            }

            $this->projectorExecutor->reset(null);
            foreach ($this->uniqueStreamFilters() as $streamFilter) {
                $streamState = $this->loadOrCreateStreamState($streamFilter->streamName);
                $this->getProjectionStateStorage()->savePartition($this->withoutProgress($streamState));
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    private function markProjectionAsInitialized(): void
    {
        $transaction = $this->getProjectionStateStorage()->beginTransaction();
        try {
            $projectionState = $this->getProjectionStateStorage()->loadPartition($this->projectionName, null);
            if ($projectionState !== null) {
                $this->getProjectionStateStorage()->savePartition($projectionState->withStatus(ProjectionInitializationStatus::INITIALIZED));
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: mixed, 2: int} last position, user state, processed events
     */
    private function projectStream(PerStreamSource $streamSource, StreamFilter $streamFilter, ?string $lastPosition, mixed $userState, bool $isRebuilding): array
    {
        $totalProcessedEvents = 0;
        do {
            $streamPage = $streamSource->loadStream($this->projectionName, $streamFilter, $lastPosition, $this->eventLoadingBatchSize);

            $batchProcessedEvents = 0;
            foreach ($streamPage->events as $event) {
                $userState = $this->projectorExecutor->project($event, $userState, $isRebuilding);
                $batchProcessedEvents++;
            }
            if ($batchProcessedEvents > 0) {
                $this->projectorExecutor->flush($userState, $isRebuilding);
            }

            $totalProcessedEvents += $batchProcessedEvents;
            $lastPosition = $streamPage->lastPosition;
        } while ($isRebuilding && $batchProcessedEvents >= $this->eventLoadingBatchSize);

        return [$lastPosition, $userState, $totalProcessedEvents];
    }

    private function moveCombinedPositionIntoStreamPositions(PerStreamSource $streamSource, ProjectionPartitionState $projectionState): ProjectionPartitionState
    {
        if ($projectionState->lastPosition === null || $projectionState->lastPosition === '') {
            return $projectionState->withLastPosition('');
        }

        $storage = $this->getProjectionStateStorage();
        foreach ($streamSource->splitCombinedPositionIntoStreamPositions($projectionState->lastPosition) as $streamName => $streamPosition) {
            if ($storage->loadPartition($this->projectionName, $streamName) === null) {
                $storage->savePartition(new ProjectionPartitionState(
                    $this->projectionName,
                    $streamName,
                    $streamPosition,
                    null,
                    ProjectionInitializationStatus::INITIALIZED,
                ));
            }
        }

        $projectionState = $projectionState->withLastPosition('');
        $storage->savePartition($projectionState);

        return $projectionState;
    }

    private function loadOrCreateStreamState(string $streamName): ProjectionPartitionState
    {
        $storage = $this->getProjectionStateStorage();
        $streamState = $storage->loadPartition($this->projectionName, $streamName);
        if ($streamState !== null) {
            return $streamState;
        }

        $storage->initPartition($this->projectionName, $streamName);

        return $storage->loadPartition($this->projectionName, $streamName)
            ?? new ProjectionPartitionState($this->projectionName, $streamName, null, null, ProjectionInitializationStatus::INITIALIZED);
    }

    private function withoutProgress(ProjectionPartitionState $state): ProjectionPartitionState
    {
        return new ProjectionPartitionState($state->projectionName, $state->partitionKey, '', null, $state->status);
    }

    /**
     * @return StreamFilter[]
     */
    private function uniqueStreamFilters(): array
    {
        $uniqueStreamFilters = [];
        foreach ($this->streamFilterRegistry->provide($this->projectionName) as $streamFilter) {
            $uniqueStreamFilters[$streamFilter->streamName] ??= $streamFilter;
        }

        return array_values($uniqueStreamFilters);
    }

    private function sendPartitionBatchMessage(array $headers, ?string $asyncChannelName): void
    {
        if ($asyncChannelName !== null) {
            $this->messagingEntrypoint->sendWithHeaders(
                $this->projectionName,
                $headers,
                $asyncChannelName,
                PartitionBatchExecutorHandler::PARTITION_BATCH_EXECUTOR_CHANNEL
            );
        } else {
            $this->messagingEntrypoint->sendWithHeaders(
                $this->projectionName,
                $headers,
                PartitionBatchExecutorHandler::PARTITION_BATCH_EXECUTOR_CHANNEL
            );
        }
    }

    private function loadOrInitializePartitionState(?string $partitionKey, bool $canInitialize): ?ProjectionPartitionState
    {
        $storage = $this->getProjectionStateStorage();
        $projectionState = $storage->loadPartition($this->projectionName, $partitionKey);

        if (! $canInitialize && $projectionState?->status === ProjectionInitializationStatus::UNINITIALIZED) {
            return null;
        }
        if ($projectionState) {
            return $projectionState;
        }

        if ($canInitialize) {
            $projectionState = $storage->initPartition($this->projectionName, $partitionKey);
            if ($projectionState) {
                $this->projectorExecutor->init();
            } else {
                $projectionState = $storage->loadPartition($this->projectionName, $partitionKey);
            }
            return $projectionState;
        }
        return null;
    }
}
