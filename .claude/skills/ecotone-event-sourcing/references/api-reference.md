# Event Sourcing API Reference

## Projection Attribute

Source: `Ecotone\Api\Projection`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Projection
{
    public function __construct(
        public readonly string $name,
    )
}
```

## FromStream Attribute

Source: `Ecotone\Api\FromStream`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class FromStream
{
    public function __construct(
        public readonly string $stream,
        public readonly ?string $aggregateType = null,
    )
}
```

## FromAggregateStream Attribute

Source: `Ecotone\Api\FromAggregateStream`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class FromAggregateStream
{
    public function __construct(
        public readonly string $aggregateClass,
    )
}
```

Requires the referenced class to be an `#[EventSourcingAggregate]`.

## Partitioned Attribute

Source: `Ecotone\Api\Partitioned`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Partitioned
{
    public function __construct(
        public readonly ?string $headerName = null,
    )
}
```

- Default partition key: `MessageHeaders::EVENT_AGGREGATE_ID`
- Custom key: `#[Partitioned('custom_header')]`

## Polling Attribute

Source: `Ecotone\Api\Polling`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Polling
{
    public function __construct(
        public readonly string $endpointId,
    )
}
```

## Streaming Attribute

Source: `Ecotone\Api\Streaming`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Streaming
{
    public function __construct(
        public readonly string $channelName,
    )
}
```

## Lifecycle Attributes

| Attribute | Source | When Called |
|-----------|--------|-----------|
| `#[ProjectionInitialization]` | `Ecotone\Api\ProjectionInitialization` | On first run / initialization |
| `#[ProjectionDelete]` | `Ecotone\Api\ProjectionDelete` | When projection is deleted |
| `#[ProjectionReset]` | `Ecotone\Api\ProjectionReset` | When projection is reset |
| `#[ProjectionFlush]` | `Ecotone\EventSourcing\Attribute\ProjectionFlush` | After each batch of events |

All are `#[Attribute(Attribute::TARGET_METHOD)]` with no constructor parameters.

## Configuration Attributes

### ProjectionExecution

Source: `Ecotone\Api\ProjectionExecution`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionExecution
{
    public function __construct(
        public readonly int $eventLoadingBatchSize = 1000,
    )
}
```

### ProjectionBackfill

Source: `Ecotone\Api\ProjectionBackfill`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionBackfill
{
    public function __construct(
        public readonly int $backfillPartitionBatchSize = 100,
        public readonly ?string $asyncChannelName = null,
    )
}
```

### ProjectionDeployment

Source: `Ecotone\Api\ProjectionDeployment`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionDeployment
{
    public function __construct(
        public readonly bool $live = true,
        public readonly bool $manualKickOff = false,
    )
}
```

## ProjectionState Parameter Attribute

Source: `Ecotone\Api\ProjectionState`

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class ProjectionState
{
}
```

Used on event handler parameters to receive and return projection state:

```php
#[EventHandler]
public function onEvent(SomeEvent $event, #[ProjectionState] array $state = []): array
{
    $state['count'] = ($state['count'] ?? 0) + 1;
    return $state;  // Return to persist
}
```

## Revision Attribute

Source: `Ecotone\Api\Revision`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Revision
{
    public function __construct(
        public readonly int $revision,
    )
}
```

- Default revision is 1 when no attribute present
- Stored in metadata as `MessageHeaders::REVISION`

## NamedEvent Attribute

Source: `Ecotone\Api\NamedEvent`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class NamedEvent
{
    public function __construct(
        public readonly string $name,
    )
}
```

## EventStore Interface

Source: `Ecotone\EventSourcing\EventStore`

```php
interface EventStore
{
    public function create(string $streamName, array $streamEvents = [], array $streamMetadata = []): void;
    public function appendTo(string $streamName, array $streamEvents): void;
    public function delete(string $streamName): void;
    public function hasStream(string $streamName): bool;
    public function load(string $streamName, int $fromNumber = 1, ?int $count = null, ...): iterable;
}
```

## EventStreamEmitter

Source: `Ecotone\EventSourcing\EventStreamEmitter`

Available in projection event handler methods:

```php
#[EventHandler]
public function onEvent(SomeEvent $event, EventStreamEmitter $emitter): void
{
    $emitter->linkTo('stream_name', [new SomeOtherEvent(...)]);
    $emitter->emit([new AnotherEvent(...)]);  // Emit to projection's own stream
}
```

## Validation Rules

1. `#[Partitioned]` + multiple `#[FromStream]` -> ConfigurationException
2. `#[FromAggregateStream]` requires `#[EventSourcingAggregate]` class
3. `#[Polling]` + `#[Streaming]` -> not allowed
4. `#[Polling]` + `#[Partitioned]` -> not allowed
5. `#[Partitioned]` + `#[Streaming]` -> not allowed
6. Projection names must be unique
7. Backfill batch size must be >= 1
