# Partitioned ProjectionV2 - Multiple Streams Support

## Problem Statement

Currently, partitioned projections (`#[Partitioned]` attribute) with `#[ProjectionV2]` only support a single stream. When a projection consumes from multiple streams (multiple `#[FromStream]` or `#[FromAggregateStream]` attributes), two issues arise:

1. **Position Tracking Collision**: If the same partition key (e.g., aggregate ID `"123"`) exists in two different streams, they share the same position state, causing events to be skipped or processed incorrectly.

2. **Catch-up Incomplete**: Triggering the projection only catches up events from one stream, leaving other streams unprocessed.

## Current Architecture Analysis

### Key Components

| Component | Description |
|-----------|-------------|
| `ProjectionPartitionState` | Stores: `projectionName`, `partitionKey`, `lastPosition`, `userState`, `status` |
| `StreamFilterRegistry` | Provides `StreamFilter[]` per projection (supports multiple streams) |
| `EventStoreAggregateStreamSource` | Handles partitioned loading - currently only uses `$streamFilters[0]` |
| `InMemoryProjectionStateStorage` | Key format: `{projectionName}-{partitionKey}` (no stream awareness) |
| `DbalProjectionStateStorage` | Primary key: `(projection_name, partition_key)` (no stream column) |
| `ProjectingManager::execute()` | Loads from single stream source, stores single position |

### Current Table Schema

```sql
-- PostgreSQL
CREATE TABLE ecotone_projection_state (
    projection_name VARCHAR(255) NOT NULL,
    partition_key VARCHAR(255) NOT NULL DEFAULT '',
    last_position TEXT NOT NULL,
    metadata JSON NOT NULL,
    user_state JSON,
    PRIMARY KEY (projection_name, partition_key)
)
```

### Root Causes

1. **`EventStoreAggregateStreamSource::load()`** ignores multiple stream filters:
   ```php
   $streamFilter = $streamFilters[0]; // Only first stream used!
   ```

2. **State storage key** doesn't include stream name - partitions from different streams collide:
   - Stream A, partition "123" → key: `my_projection-123`
   - Stream B, partition "123" → key: `my_projection-123` (COLLISION!)

3. **`ProjectingManager::execute()`** processes only one stream per execution cycle.

4. **`ProjectionStateStorage::loadPartition()`** has no stream context - cannot distinguish between same partition key in different streams.

## Proposed Solution

### Stream-Aware State Storage

Extend the state storage to include stream information, making each (projection, stream, partition) combination unique.

#### Changes Required:

**1. `ProjectionPartitionState`** - Add `streamName` field:
```php
public function __construct(
    public readonly string  $projectionName,
    public readonly ?string $partitionKey,
    public readonly ?string $streamName,  // NEW - required
    public readonly ?string $lastPosition = null,
    public readonly mixed   $userState = null,
    public readonly ?ProjectionInitializationStatus $status = null,
)
```

**2. `ProjectionStateStorage` interface** - Add `streamName` parameter:
```php
public function loadPartition(string $projectionName, ?string $partitionKey, string $streamName, bool $lock = true): ?ProjectionPartitionState;
public function initPartition(string $projectionName, ?string $partitionKey, string $streamName): ?ProjectionPartitionState;
public function savePartition(ProjectionPartitionState $projectionState): void;  // Uses streamName from state object
```

**3. `StreamSource` interface** - Add `streamName` parameter:
```php
public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey, string $streamName): StreamPage;
```

**4. `InMemoryProjectionStateStorage`** - Update key generation:
```php
private function getKey(string $projectionName, ?string $partitionKey, string $streamName): string
{
    $key = $projectionName;
    if ($streamName !== '') {
        $key .= '::' . $streamName;
    }
    if ($partitionKey !== null) {
        $key .= '-' . $partitionKey;
    }
    return $key;
}
```

**5. `ProjectionStateTableManager`** - Update schema to include `stream_name`:
```sql
-- PostgreSQL
CREATE TABLE ecotone_projection_state (
    projection_name VARCHAR(255) NOT NULL,
    stream_name VARCHAR(255) NOT NULL DEFAULT '',  -- NEW COLUMN
    partition_key VARCHAR(255) NOT NULL DEFAULT '',
    last_position TEXT NOT NULL,
    metadata JSON NOT NULL,
    user_state JSON,
    PRIMARY KEY (projection_name, stream_name, partition_key)  -- UPDATED
)

-- MySQL
CREATE TABLE `ecotone_projection_state` (
    `projection_name` VARCHAR(255) NOT NULL,
    `stream_name` VARCHAR(255) NOT NULL DEFAULT '',  -- NEW COLUMN
    `partition_key` VARCHAR(255) NOT NULL DEFAULT '',
    `last_position` TEXT NOT NULL,
    `metadata` JSON NOT NULL,
    `user_state` JSON,
    PRIMARY KEY (`projection_name`, `stream_name`, `partition_key`)  -- UPDATED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

**6. `DbalProjectionStateStorage`** - Update all queries to include `stream_name`:
```php
// loadPartition
$query = <<<SQL
    SELECT last_position, user_state, metadata FROM {$tableName}
    WHERE projection_name = :projectionName
      AND stream_name = :streamName
      AND partition_key = :partitionKey
    SQL;

// initPartition & savePartition - similar updates
```

**7. `EventStoreAggregateStreamSource::load()`** - Use passed `streamName` to find matching StreamFilter:
```php
public function load(string $projectionName, ?string $lastPosition, int $count, ?string $partitionKey, string $streamName): StreamPage
{
    Assert::notNull($partitionKey, 'Partition key cannot be null for aggregate stream source');

    $streamFilters = $this->streamFilterRegistry->provide($projectionName);
    $streamFilter = $this->findStreamFilterByName($streamFilters, $streamName);

    // Use $streamFilter for loading...
}
```

**8. `ProjectingManager::execute()`** - Require stream name, iterate over all streams when triggering:
```php
public function execute(?string $partitionKeyValue, string $streamName, bool $manualInitialization = false): void
{
    do {
        $processedEvents = $this->executeSingleBatch($partitionKeyValue, $streamName, $manualInitialization || $this->automaticInitialization);
    } while ($processedEvents > 0 && $this->terminationListener->shouldTerminate() !== true);
}

public function executeAllStreams(?string $partitionKeyValue = null, bool $manualInitialization = false): void
{
    $streamFilters = $this->streamFilterRegistry->provide($this->projectionName);
    foreach ($streamFilters as $streamFilter) {
        $this->execute($partitionKeyValue, $streamFilter->streamName, $manualInitialization);
    }
}
```

### Table Schema Change Required: YES

The database table **must** be updated to add `stream_name` column as part of the composite primary key.

### Migration Strategy

1. **New installations**: Create table with new schema including `stream_name`
2. **Existing installations**:
   - Add `stream_name` column with default `''`
   - Update primary key to include `stream_name`
   - Existing data continues to work (single-stream projections use `stream_name = ''`)

## Implementation Plan

### Phase 1: Core Infrastructure (Ecotone Package)
1. Update `ProjectionPartitionState` - add `streamName` property (required)
2. Update `ProjectionStateStorage` interface - add `streamName` parameter to `loadPartition`, `initPartition`
3. Update `StreamSource` interface - add `streamName` parameter to `load`
4. Update `InMemoryProjectionStateStorage` - stream-aware key generation
5. Update `InMemoryStreamSource` - update `load` method signature
6. Update `ProjectingManager` - require `streamName` in `execute()`, add `executeAllStreams()` method

### Phase 2: Database Storage (PdoEventSourcing Package)
1. Update `ProjectionStateTableManager` - add `stream_name` column to schema
2. Update `DbalProjectionStateStorage` - include `stream_name` in all queries
3. Update `EventStoreAggregateStreamSource` - use passed `streamName` to find matching StreamFilter

### Phase 3: Testing
1. Add unit tests for multi-stream partitioned projections using EcotoneLite
2. Test partition key collision prevention across streams
3. Test catch-up behavior for all streams
4. All tests use inline anonymous classes

## Test Scenarios

```php
public function test_partitioned_projection_with_multiple_streams_tracks_positions_separately(): void
{
    // Given: Projection with two FromStream attributes and Partitioned
    // And: Same partition key exists in both streams
    // When: Projection is triggered for all streams
    // Then: Events from both streams are processed
    // And: Position for each stream is tracked independently
}

public function test_partitioned_projection_catches_up_all_streams(): void
{
    // Given: Projection with multiple streams
    // And: Events exist in all streams
    // When: executeAllStreams() is called
    // Then: All streams are caught up completely
}

public function test_same_partition_key_in_different_streams_does_not_collide(): void
{
    // Given: Stream A has partition "123" at position 5
    // And: Stream B has partition "123" at position 10
    // When: New events are added to both streams
    // Then: Each stream's partition continues from its own position
}
```

## Files to Modify

### Ecotone Package
| File | Change |
|------|--------|
| `src/Projecting/ProjectionPartitionState.php` | Add `streamName` property (required) |
| `src/Projecting/ProjectionStateStorage.php` | Add `streamName` param to `loadPartition`, `initPartition` |
| `src/Projecting/StreamSource.php` | Add `streamName` param to `load` |
| `src/Projecting/InMemory/InMemoryProjectionStateStorage.php` | Stream-aware key generation |
| `src/Projecting/InMemory/InMemoryStreamSource.php` | Update `load` method signature |
| `src/Projecting/ProjectingManager.php` | Require `streamName` in `execute()`, add `executeAllStreams()` |
| `tests/Projecting/ProjectingTest.php` | Add multi-stream partitioned tests |

### PdoEventSourcing Package
| File | Change |
|------|--------|
| `src/Database/ProjectionStateTableManager.php` | Add `stream_name` column |
| `src/Projecting/PartitionState/DbalProjectionStateStorage.php` | Include `stream_name` in queries |
| `src/Projecting/StreamSource/EventStoreAggregateStreamSource.php` | Use passed `streamName` to find StreamFilter |

## Estimated Effort

| Task | Estimate |
|------|----------|
| Core infrastructure changes | 4-6 hours |
| Database storage changes | 2-3 hours |
| Testing | 3-4 hours |
| **Total** | **9-13 hours** |

## Design Decisions

1. **Partition header**: The partition header comes from the Event Message and is configured via `#[Partitioned]` attribute at the class level. The same partition header is used for all streams.

2. **No backward compatibility**: This feature is not yet live, so method signatures are changed explicitly without optional parameters for backward compatibility.

3. **Stream name required**: All methods that deal with partition state now require an explicit `streamName` parameter to ensure proper isolation between streams.

