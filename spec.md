# Specification: Partitioned ProjectionV2 with Multiple Streams

## Overview

This specification outlines the implementation of multi-stream support for Partitioned ProjectionV2. Currently, partitioned projections are limited to a single stream. This feature will allow a partitioned projection to subscribe to multiple streams while ensuring:

1. Partition keys are tracked **separately per stream** (even if the same partition key value exists in multiple streams)
2. Triggering the projection catches up **all streams**
3. Backfill works across all streams

## Current State Analysis

### Limitation in Configuration
**File:** `packages/PdoEventSourcing/src/Config/ProophProjectingModule.php:110-114`

```php
if ($isPartitioned && count($streamFilters) > 1) {
    throw ConfigurationException::create(
        "Partitioned projection {$projectionName} cannot declare multiple streams..."
    );
}
```

### Current Position Tracking
- **Storage key:** `(projection_name, partition_key)` - no stream differentiation
- **Position format:** aggregate version (integer as string)
- **Table:** `ecotone_projection_state` with columns: `projection_name`, `partition_key`, `last_position`, `user_state`, `metadata`

### Current Stream Source Behavior
**File:** `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreAggregateStreamSource.php:49`

```php
$streamFilter = $streamFilters[0]; // Only uses first stream!
```

## Design Decision: Add Stream Name to Database Schema

The storage key will be extended to include `stream_name`:

- **New storage key:** `(projection_name, partition_key, stream_name)`
- **New column:** `stream_name VARCHAR(255) NOT NULL DEFAULT ''`

**Rationale:**
- Clean, explicit data model
- Proper querying by stream
- ProjectingManager becomes stream-aware
- No encoding/decoding complexity

### Current Schema
```sql
PRIMARY KEY (projection_name, partition_key)
```

### New Schema
```sql
PRIMARY KEY (projection_name, partition_key, stream_name)
```

## Implementation Plan

### Phase 1: Update Database Schema

**File:** `packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php`

**Changes:**
1. Add `stream_name` column to table schema
2. Update primary key to include `stream_name`

**PostgreSQL:**
```sql
CREATE TABLE IF NOT EXISTS ecotone_projection_state (
    projection_name VARCHAR(255) NOT NULL,
    partition_key VARCHAR(255) NOT NULL DEFAULT '',
    stream_name VARCHAR(255) NOT NULL DEFAULT '',
    last_position TEXT NOT NULL,
    metadata JSON NOT NULL,
    user_state JSON,
    PRIMARY KEY (projection_name, partition_key, stream_name)
)
```

**MySQL:**
```sql
CREATE TABLE IF NOT EXISTS `ecotone_projection_state` (
    `projection_name` VARCHAR(255) NOT NULL,
    `partition_key` VARCHAR(255) NOT NULL DEFAULT '',
    `stream_name` VARCHAR(255) NOT NULL DEFAULT '',
    `last_position` TEXT NOT NULL,
    `metadata` JSON NOT NULL,
    `user_state` JSON,
    PRIMARY KEY (`projection_name`, `partition_key`, `stream_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

### Phase 2: Update ProjectionPartitionState

**File:** `packages/Ecotone/src/Projecting/ProjectionPartitionState.php`

**Changes:**
Add `streamName` property to track which stream this state belongs to:

```php
class ProjectionPartitionState
{
    public function __construct(
        public readonly string                          $projectionName,
        public readonly ?string                         $partitionKey,
        public readonly ?string                         $streamName = null,  // NEW
        public readonly ?string                         $lastPosition = null,
        public readonly mixed                           $userState = null,
        public readonly ?ProjectionInitializationStatus $status = null,
    ) {
    }
}
```

### Phase 3: Update DbalProjectionStateStorage

**File:** `packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php`

**Changes:**
1. Update all queries to include `stream_name` in WHERE clause and PRIMARY KEY
2. Update `loadPartition()` signature to accept `?string $streamName = null`
3. Update `initPartition()` signature to accept `?string $streamName = null`
4. Update `savePartition()` to persist `streamName`

```php
public function loadPartition(
    string $projectionName,
    ?string $partitionKey = null,
    ?string $streamName = null,  // NEW
    bool $lock = true
): ?ProjectionPartitionState

public function initPartition(
    string $projectionName,
    ?string $partitionKey = null,
    ?string $streamName = null   // NEW
): ?ProjectionPartitionState
```

### Phase 4: Update ProjectionStateStorage Interface

**File:** `packages/Ecotone/src/Projecting/ProjectionStateStorage.php`

**Changes:**
Add `streamName` parameter to interface methods:

```php
interface ProjectionStateStorage
{
    public function loadPartition(
        string $projectionName,
        ?string $partitionKey = null,
        ?string $streamName = null,
        bool $lock = true
    ): ?ProjectionPartitionState;

    public function initPartition(
        string $projectionName,
        ?string $partitionKey = null,
        ?string $streamName = null
    ): ?ProjectionPartitionState;

    // ... other methods unchanged
}
```

### Phase 5: Remove Configuration Restriction

**File:** `packages/PdoEventSourcing/src/Config/ProophProjectingModule.php`

**Changes:**
1. Remove the check that throws exception for partitioned projections with multiple streams (lines 110-114)

### Phase 6: Update EventStoreAggregateStreamSource

**File:** `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreAggregateStreamSource.php`

**Changes:**
1. Add `streamName` parameter to `load()` method signature
2. Use `streamName` to find the correct stream filter

```php
public function load(
    string $projectionName,
    ?string $lastPosition,
    int $count,
    ?string $partitionKey = null,
    ?string $streamName = null  // NEW
): StreamPage
{
    Assert::notNull($partitionKey, 'Partition key cannot be null');
    Assert::notNull($streamName, 'Stream name cannot be null for multi-stream projection');

    $streamFilters = $this->streamFilterRegistry->provide($projectionName);
    $streamFilter = $this->findStreamFilterByName($streamFilters, $streamName);

    // ... rest uses $streamFilter
}
```

### Phase 7: Update StreamSource Interface

**File:** `packages/Ecotone/src/Projecting/StreamSource.php`

**Changes:**
Add `streamName` parameter:

```php
interface StreamSource
{
    public function load(
        string $projectionName,
        ?string $lastPosition,
        int $count,
        ?string $partitionKey = null,
        ?string $streamName = null  // NEW
    ): StreamPage;
}
```

### Phase 8: Update ProjectingManager for Multi-Stream Awareness

**File:** `packages/Ecotone/src/Projecting/ProjectingManager.php`

**Changes:**
1. Add `streamName` tracking throughout execution
2. Modify `execute()` to handle multi-stream:
   - Accept optional `streamName` parameter
   - When `streamName` is null and projection has multiple streams, iterate all streams

```php
public function execute(
    ?string $partitionKeyValue = null,
    bool $manualInitialization = false,
    ?string $streamName = null  // NEW
): void
{
    $streamFilters = $this->streamFilterRegistry->provide($this->projectionName);

    if ($streamName !== null) {
        // Execute for specific stream
        $this->executeForStream($partitionKeyValue, $streamName, $manualInitialization);
        return;
    }

    // Execute for all streams (trigger scenario)
    foreach ($streamFilters as $streamFilter) {
        $this->executeForStream($partitionKeyValue, $streamFilter->streamName, $manualInitialization);
    }
}

private function executeForStream(
    ?string $partitionKeyValue,
    string $streamName,
    bool $canInitialize
): void
{
    do {
        $processedEvents = $this->executeSingleBatch($partitionKeyValue, $streamName, $canInitialize);
    } while ($processedEvents > 0 && $this->terminationListener->shouldTerminate() !== true);
}

private function executeSingleBatch(
    ?string $partitionKeyValue,
    string $streamName,
    bool $canInitialize
): int
{
    $transaction = $this->getProjectionStateStorage()->beginTransaction();
    try {
        $projectionState = $this->loadOrInitializePartitionState($partitionKeyValue, $streamName, $canInitialize);
        // ... rest of execution with stream awareness

        $streamSource->load(
            $this->projectionName,
            $projectionState->lastPosition,
            $this->eventLoadingBatchSize,
            $partitionKeyValue,
            $streamName  // Pass stream name
        );

        // ...
    }
}
```

### Phase 9: Update AggregateIdPartitionProvider

**File:** `packages/PdoEventSourcing/src/Projecting/AggregateIdPartitionProvider.php`

No changes needed - it already accepts `StreamFilter` which contains `streamName`. The backfill mechanism already passes stream information.

### Phase 10: Update BackfillExecutorHandler

**File:** `packages/Ecotone/src/Projecting/BackfillExecutorHandler.php`

**Changes:**
Pass `streamName` when executing:

```php
public function executeBackfillBatch(
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
        $projectingManager->execute($partition, true, $streamName);  // Pass stream name
        if ($this->terminationListener->shouldTerminate()) {
            break;
        }
    }
}
```

### Phase 11: Update EventStoreGlobalStreamSource (for consistency)

**File:** `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreGlobalStreamSource.php`

**Changes:**
Add `streamName` parameter to match interface (can ignore it for global stream source):

```php
public function load(
    string $projectionName,
    ?string $lastPosition,
    int $count,
    ?string $partitionKey = null,
    ?string $streamName = null  // NEW - ignored for global stream
): StreamPage
```

## Test Cases

All tests should use EcotoneLite with inline classes.

### Test 1: Multi-Stream Partitioned Projection - Basic Operation

```php
public function test_partitioned_projection_with_multiple_streams(): void
{
    // Define two aggregates: Ticket and Order
    // Define projection with #[FromStream(Ticket::class), FromStream(Order::class), Partitioned]
    // Create events in both streams
    // Verify projection handles events from both streams
}
```

### Test 2: Same Partition Key in Different Streams - Separate Tracking

```php
public function test_same_partition_key_in_different_streams_tracked_separately(): void
{
    // Create Ticket with ID "123" and 2 events
    // Create Order with ID "123" and 3 events
    // Verify projection tracks stream "Ticket" with partition "123" at version 2
    // Verify projection tracks stream "Order" with partition "123" at version 3
    // These should be completely independent rows in the database
}
```

### Test 3: Trigger Projection Catches Up All Streams

```php
public function test_trigger_catches_up_all_streams(): void
{
    // Bootstrap with multi-stream partitioned projection
    // Create events in Stream A
    // Create events in Stream B
    // Reset projection
    // Trigger projection
    // Verify all events from both streams are processed
}
```

### Test 4: Backfill Works Across Multiple Streams

```php
public function test_backfill_processes_all_streams(): void
{
    // Create multiple aggregates in Stream A
    // Create multiple aggregates in Stream B
    // Initialize projection
    // Run backfill
    // Verify all partitions from both streams are processed
}
```

### Test 5: Backwards Compatibility - Single Stream Still Works

```php
public function test_single_stream_partitioned_projection_unchanged(): void
{
    // Define projection with single #[FromStream], Partitioned
    // Verify it works exactly as before
    // Stream name defaults to empty string or the single stream name
}
```

## Example Usage

### Multi-Stream Partitioned Projection

```php
#[ProjectionV2('combined_activity_log')]
#[Partitioned(MessageHeaders::EVENT_AGGREGATE_ID)]
#[FromStream(stream: Ticket::class, aggregateType: Ticket::class)]
#[FromStream(stream: Order::class, aggregateType: Order::class)]
class CombinedActivityLogProjection
{
    public function __construct(private Connection $connection) {}

    #[EventHandler]
    public function onTicketRegistered(TicketWasRegistered $event): void
    {
        $this->connection->insert('activity_log', [
            'entity_type' => 'ticket',
            'entity_id' => $event->getTicketId(),
            'action' => 'registered',
        ]);
    }

    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        $this->connection->insert('activity_log', [
            'entity_type' => 'order',
            'entity_id' => $event->orderId,
            'action' => 'placed',
        ]);
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        // Create activity_log table
    }
}
```

## Files to Modify

| Phase | File | Change |
|-------|------|--------|
| 1 | `packages/PdoEventSourcing/src/Database/ProjectionStateTableManager.php` | Add `stream_name` column and update primary key |
| 2 | `packages/Ecotone/src/Projecting/ProjectionPartitionState.php` | Add `streamName` property |
| 3 | `packages/PdoEventSourcing/src/Projecting/PartitionState/DbalProjectionStateStorage.php` | Update all queries for `stream_name` |
| 4 | `packages/Ecotone/src/Projecting/ProjectionStateStorage.php` | Add `streamName` to interface methods |
| 5 | `packages/PdoEventSourcing/src/Config/ProophProjectingModule.php` | Remove multi-stream restriction |
| 6 | `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreAggregateStreamSource.php` | Add `streamName` parameter, find correct filter |
| 7 | `packages/Ecotone/src/Projecting/StreamSource.php` | Add `streamName` to interface |
| 8 | `packages/Ecotone/src/Projecting/ProjectingManager.php` | Multi-stream execution logic |
| 9 | `packages/PdoEventSourcing/src/Projecting/AggregateIdPartitionProvider.php` | No changes needed |
| 10 | `packages/Ecotone/src/Projecting/BackfillExecutorHandler.php` | Pass `streamName` to execute |
| 11 | `packages/PdoEventSourcing/src/Projecting/StreamSource/EventStoreGlobalStreamSource.php` | Add `streamName` parameter |
| NEW | `packages/PdoEventSourcing/tests/Projecting/Partitioned/MultiStreamPartitionedProjectionTest.php` | Test cases |

## Migration Notes

For users with existing `ecotone_projection_state` tables:
1. Drop and recreate the table (projections will rebuild from scratch)
2. Or run migration: `ALTER TABLE ecotone_projection_state ADD COLUMN stream_name VARCHAR(255) NOT NULL DEFAULT '', DROP PRIMARY KEY, ADD PRIMARY KEY (projection_name, partition_key, stream_name)`

## Backwards Compatibility

- Single-stream projections continue to work (stream_name will be the single stream's name)
- No functional changes for existing single-stream partitioned projections
- Database schema change requires table recreation or migration
