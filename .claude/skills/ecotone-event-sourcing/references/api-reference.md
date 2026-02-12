# Event Sourcing API Reference

## ProjectionV2 Attribute

Source: `Ecotone\Projecting\Attribute\ProjectionV2`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class ProjectionV2
{
    public function __construct(
        public readonly string $name,
    )
}
```

## FromStream Attribute

Source: `Ecotone\Projecting\Attribute\FromStream`

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

Source: `Ecotone\Projecting\Attribute\FromAggregateStream`

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

Source: `Ecotone\Projecting\Attribute\Partitioned`

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

Source: `Ecotone\Projecting\Attribute\Polling`

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

Source: `Ecotone\Projecting\Attribute\Streaming`

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
| `#[ProjectionInitialization]` | `Ecotone\EventSourcing\Attribute\ProjectionInitialization` | On first run / initialization |
| `#[ProjectionDelete]` | `Ecotone\EventSourcing\Attribute\ProjectionDelete` | When projection is deleted |
| `#[ProjectionReset]` | `Ecotone\EventSourcing\Attribute\ProjectionReset` | When projection is reset |
| `#[ProjectionFlush]` | `Ecotone\EventSourcing\Attribute\ProjectionFlush` | After each batch of events |

All are `#[Attribute(Attribute::TARGET_METHOD)]` with no constructor parameters.

## Configuration Attributes

### ProjectionExecution

Source: `Ecotone\Projecting\Attribute\ProjectionExecution`

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

Source: `Ecotone\Projecting\Attribute\ProjectionBackfill`

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

Source: `Ecotone\Projecting\Attribute\ProjectionDeployment`

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

Source: `Ecotone\EventSourcing\Attribute\ProjectionState`

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

Source: `Ecotone\Modelling\Attribute\Revision`

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

Source: `Ecotone\Modelling\Attribute\NamedEvent`

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
