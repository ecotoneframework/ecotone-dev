# Plan: Add `rebuild` Command for ProjectionV2

## Context

ProjectionV2 has `backfill` but lacks a `rebuild` command that resets tracking and re-processes from scratch. For partitioned projections, rebuild must work per-partition: reset that partition's tracking, call the rebuild handler with partition context (aggregate ID, aggregate type, stream name), then re-process all events — all within the same transaction. For global projections, it resets the single global tracking and re-processes. Batching follows `ProjectionBackfill` attribute config.

## Changes

### 1. Create `ProjectionRebuild` method attribute
**New file:** `packages/Ecotone/src/Projecting/Attribute/ProjectionRebuild.php`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class ProjectionRebuild {}
```

Follow the pattern of `ProjectionFlush`, `ProjectionDelete` in the same directory.

### 2. Create parameter attributes for rebuild context
**New files in** `packages/Ecotone/src/Projecting/Attribute/`:

Each extends `Header` (like `ProjectionName` and `AggregateIdentifier`), returning a specific header name:

- **`PartitionAggregateId.php`** — `extends Header`, returns `ProjectingHeaders::PARTITION_AGGREGATE_ID`
- **`PartitionAggregateType.php`** — `extends Header`, returns `ProjectingHeaders::PARTITION_AGGREGATE_TYPE`
- **`PartitionStreamName.php`** — `extends Header`, returns `ProjectingHeaders::PARTITION_STREAM_NAME`

User's rebuild handler uses them as parameter annotations:
```php
#[ProjectionRebuild]
public function rebuild(
    #[PartitionAggregateId] string $aggregateId,
    #[PartitionAggregateType] string $aggregateType,
    #[PartitionStreamName] string $streamName,
): void {
    $this->connection->executeStatement("DELETE FROM tickets WHERE ticket_id = ?", [$aggregateId]);
}
```

No `HeaderBuilder` converters needed — resolution happens automatically via `Header` attribute.

### 3. Add partition header constants to `ProjectingHeaders`
**File:** `packages/Ecotone/src/Projecting/ProjectingHeaders.php`

```php
public const PARTITION_AGGREGATE_ID = 'partition.aggregateId';
public const PARTITION_AGGREGATE_TYPE = 'partition.aggregateType';
public const PARTITION_STREAM_NAME = 'partition.streamName';
```

### 4. Add `rebuild()` to `ProjectorExecutor` interface
**File:** `packages/Ecotone/src/Projecting/ProjectorExecutor.php`

```php
public function rebuild(?string $aggregateId = null, ?string $aggregateType = null, ?string $streamName = null): void;
```

### 5. Implement `rebuild()` in all `ProjectorExecutor` implementations

**`EcotoneProjectorExecutor.php`** — Add `?string $rebuildChannel = null` as the **last** constructor parameter. Implement `rebuild()` dispatching to `$rebuildChannel` with headers:
- `ProjectingHeaders::PROJECTION_NAME`
- `ProjectingHeaders::PARTITION_AGGREGATE_ID` → `$aggregateId`
- `ProjectingHeaders::PARTITION_AGGREGATE_TYPE` → `$aggregateType`
- `ProjectingHeaders::PARTITION_STREAM_NAME` → `$streamName`

**`InMemoryProjector.php`** — Add `rebuild(): void { $this->projectedEvents = []; }`

**`EventStoreChannelAdapterProjection.php`** — Add no-op `rebuild(): void {}`

### 6. Wire `rebuildChannel` through the builder
**File:** `packages/Ecotone/src/Projecting/Config/EcotoneProjectionExecutorBuilder.php`

- Add `?string $rebuildChannel = null` property
- Add `setRebuildChannel(?string $rebuildChannel): void` method
- Update `compile()` to pass `$this->rebuildChannel` as last arg to `EcotoneProjectorExecutor`

### 7. Scan `#[ProjectionRebuild]` in `ProjectingAttributeModule`
**File:** `packages/Ecotone/src/Projecting/Config/ProjectingAttributeModule.php`

- Import `Ecotone\Projecting\Attribute\ProjectionRebuild`
- Add to `$lifecycleAnnotations` merge: `findCombined(ProjectionV2::class, ProjectionRebuild::class)`
- Add branch: `instanceof ProjectionRebuild` → `$projectionBuilder->setRebuildChannel($inputChannel)`
- No special parameter converters needed — the `#[PartitionAggregateId]` etc. attributes on user's method parameters handle resolution automatically

### 8. Add `executeRebuild()` and `prepareRebuild()` to `ProjectingManager`
**File:** `packages/Ecotone/src/Projecting/ProjectingManager.php`

**`executeRebuild()`** — per-partition, all in one transaction:
```php
public function executeRebuild(?string $partitionKeyValue = null, ?string $aggregateId = null, ?string $aggregateType = null, ?string $streamName = null): void
{
    $transaction = $this->getProjectionStateStorage()->beginTransaction();
    try {
        $storage = $this->getProjectionStateStorage();

        // Lock existing partition or init new one
        $projectionState = $storage->loadPartition($this->projectionName, $partitionKeyValue, lock: true);
        if ($projectionState === null) {
            $storage->initPartition($this->projectionName, $partitionKeyValue);
        }

        // Reset partition to initial state (lastPosition=null, userState=null)
        $projectionState = new ProjectionPartitionState(
            $this->projectionName,
            $partitionKeyValue,
            null,
            null,
            ProjectionInitializationStatus::UNINITIALIZED,
        );
        $storage->savePartition($projectionState);

        // Call rebuild handler with partition context
        $this->projectorExecutor->rebuild($aggregateId, $aggregateType, $streamName);

        // Re-process all events from scratch
        $streamSource = $this->streamSourceRegistry->getFor($this->projectionName);
        do {
            $streamPage = $streamSource->load($this->projectionName, $projectionState->lastPosition, $this->eventLoadingBatchSize, $partitionKeyValue);
            $userState = $projectionState->userState;
            $processedEvents = 0;
            foreach ($streamPage->events as $event) {
                $userState = $this->projectorExecutor->project($event, $userState);
                $processedEvents++;
            }
            if ($processedEvents > 0) {
                $this->projectorExecutor->flush($userState);
            }
            $projectionState = $projectionState
                ->withLastPosition($streamPage->lastPosition)
                ->withUserState($userState)
                ->withStatus(ProjectionInitializationStatus::INITIALIZED);
        } while ($processedEvents > 0);

        $storage->savePartition($projectionState);
        $transaction->commit();
    } catch (Throwable $e) {
        $transaction->rollBack();
        throw $e;
    }
}
```

**`prepareRebuild()`** — reuses backfill batching but targets rebuild handler:
```php
public function prepareRebuild(): void
{
    $streamFilters = $this->streamFilterRegistry->provide($this->projectionName);
    foreach ($streamFilters as $streamFilter) {
        $this->prepareBatchesForFilter($streamFilter, RebuildExecutorHandler::REBUILD_EXECUTOR_CHANNEL);
    }
}
```

Refactor `prepareBackfillForFilter` → `prepareBatchesForFilter(StreamFilter, string $targetChannel)` shared between `prepareBackfill()` and `prepareRebuild()`. Similarly refactor `sendBackfillMessage` → `sendBatchMessage(array $headers, string $targetChannel)`.

### 9. Create `RebuildExecutorHandler`
**New file:** `packages/Ecotone/src/Projecting/RebuildExecutorHandler.php`

Follows `BackfillExecutorHandler` pattern. For each partition, extracts aggregate ID from the composite partition key (`{streamName}:{aggregateType}:{aggregateId}`) and calls `executeRebuild()`:

```php
class RebuildExecutorHandler
{
    public const REBUILD_EXECUTOR_CHANNEL = 'ecotone.projection.rebuild.executor';

    public function executeRebuildBatch(
        string $projectionName,
        ?int $limit = null,
        int $offset = 0,
        string $streamName = '',
        ?string $aggregateType = null,
        string $eventStoreReferenceName = '',
    ): void {
        $projectingManager = $this->projectionRegistry->get($projectionName);
        $streamFilter = new StreamFilter($streamName, $aggregateType, $eventStoreReferenceName);

        foreach ($projectingManager->getPartitionProvider()->partitions($streamFilter, $limit, $offset) as $partition) {
            // Extract aggregateId from composite key "{streamName}:{aggregateType}:{aggregateId}"
            $prefix = $streamFilter->streamName . ':' . $streamFilter->aggregateType . ':';
            $aggregateId = substr($partition, strlen($prefix));

            $projectingManager->executeRebuild($partition, $aggregateId, $streamFilter->aggregateType, $streamFilter->streamName);
            if ($this->terminationListener->shouldTerminate()) {
                break;
            }
        }
    }
}
```

For **global projections** (SinglePartitionProvider yields `null`): `executeRebuild(null, null, null, null)` — rebuild handler receives all nulls, user's method declares no parameters or optional ones.

### 10. Register `RebuildExecutorHandler` in `ProjectingModule`
**File:** `packages/Ecotone/src/Projecting/Config/ProjectingModule.php`

Register `RebuildExecutorHandler` service definition and message handler following the exact pattern of `BackfillExecutorHandler` registration (lines 155-181), but with:
- `RebuildExecutorHandler::class` service
- `executeRebuildBatch` method
- `RebuildExecutorHandler::REBUILD_EXECUTOR_CHANNEL` input channel
- Same `HeaderBuilder` mappings using `backfill.*` header names (reused from batch dispatching)

### 11. Add `ecotone:projection:rebuild` console command
**File:** `packages/Ecotone/src/Projecting/Config/ProjectingConsoleCommands.php`

```php
#[ConsoleCommand('ecotone:projection:rebuild')]
public function rebuildProjection(string $name): void
```

### 12. Update `FlowTestSupport`
**File:** `packages/Ecotone/src/Lite/Test/FlowTestSupport.php`

Add `rebuildProjection()` method:
```php
public function rebuildProjection(string $projectionName): self
{
    $this->getGateway(ProjectionRegistry::class)->get($projectionName)->prepareRebuild();
    return $this;
}
```

### 13. Tests
**New file:** `packages/PdoEventSourcing/tests/Projecting/RebuildProjectionTest.php`

Follow `BackfillProjectionTest` patterns with `AbstractTicketProjection` base class. The rebuild handler receives partition context. Test cases:

1. **Partitioned async rebuild (batch=2, 5 partitions)**: Events already processed → rebuild sends 3 batch messages → each run resets partition, calls `#[ProjectionRebuild]`, re-processes events → verify data is rebuilt correctly
2. **Partitioned sync rebuild**: All 5 partitions rebuilt immediately
3. **Global sync rebuild**: Resets global tracking, re-processes all events
4. **Global async rebuild**: Single message, re-processes after running channel
5. **Rebuild calls `#[ProjectionRebuild]` handler with partition context**: Verify `aggregateId`, `aggregateType`, `streamName` are passed to the rebuild method
6. **Rebuild without `#[ProjectionRebuild]` handler works**: Tracking resets, data overwritten by re-processing

Test projection fixture for partitioned rebuild:
```php
#[ProjectionRebuild]
public function rebuild(
    #[PartitionAggregateId] string $aggregateId,
    #[PartitionAggregateType] string $aggregateType,
    #[PartitionStreamName] string $streamName,
): void {
    $this->connection->executeStatement("DELETE FROM {$this->tableName()} WHERE ticket_id = ?", [$aggregateId]);
}
```

## Key Files

| File | Action |
|------|--------|
| `packages/Ecotone/src/Projecting/Attribute/ProjectionRebuild.php` | Create |
| `packages/Ecotone/src/Projecting/Attribute/PartitionAggregateId.php` | Create |
| `packages/Ecotone/src/Projecting/Attribute/PartitionAggregateType.php` | Create |
| `packages/Ecotone/src/Projecting/Attribute/PartitionStreamName.php` | Create |
| `packages/Ecotone/src/Projecting/RebuildExecutorHandler.php` | Create |
| `packages/Ecotone/src/Projecting/ProjectorExecutor.php` | Add `rebuild()` |
| `packages/Ecotone/src/Projecting/EcotoneProjectorExecutor.php` | Implement `rebuild()` |
| `packages/Ecotone/src/Projecting/InMemory/InMemoryProjector.php` | Implement `rebuild()` |
| `packages/Ecotone/src/Projecting/EventStoreAdapter/EventStoreChannelAdapterProjection.php` | Implement `rebuild()` |
| `packages/Ecotone/src/Projecting/ProjectingHeaders.php` | Add rebuild constants |
| `packages/Ecotone/src/Projecting/ProjectingManager.php` | Add `executeRebuild()`, `prepareRebuild()`, refactor batch helpers |
| `packages/Ecotone/src/Projecting/Config/EcotoneProjectionExecutorBuilder.php` | Add `rebuildChannel` |
| `packages/Ecotone/src/Projecting/Config/ProjectingAttributeModule.php` | Scan `#[ProjectionRebuild]` |
| `packages/Ecotone/src/Projecting/Config/ProjectingModule.php` | Register `RebuildExecutorHandler` |
| `packages/Ecotone/src/Projecting/Config/ProjectingConsoleCommands.php` | Add rebuild command |
| `packages/Ecotone/src/Lite/Test/FlowTestSupport.php` | Add `rebuildProjection()` |
| `packages/PdoEventSourcing/tests/Projecting/RebuildProjectionTest.php` | Create |

## Verification

1. Run existing backfill tests (no regressions): `vendor/bin/phpunit packages/PdoEventSourcing/tests/Projecting/BackfillProjectionTest.php`
2. Run new rebuild tests: `vendor/bin/phpunit packages/PdoEventSourcing/tests/Projecting/RebuildProjectionTest.php`
3. Run full projecting test suite: `vendor/bin/phpunit packages/PdoEventSourcing/tests/Projecting/`
