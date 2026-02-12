---
name: ecotone-event-sourcing
description: >-
  Implements event sourcing in Ecotone: ProjectionV2 with partitioning
  and streaming, event store configuration, event versioning/upcasting,
  and Dynamic Consistency Boundary (DCB) patterns. Use when working with
  projections, event store, event versioning, or DCB.
---

# Ecotone Event Sourcing

## 1. Event-Sourced Aggregates

```php
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class Ticket
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $ticketId;
    private bool $isClosed = false;

    #[CommandHandler]
    public static function register(RegisterTicket $command): array
    {
        return [new TicketWasRegistered($command->ticketId, $command->type)];
    }

    #[CommandHandler]
    public function close(CloseTicket $command): array
    {
        return [new TicketWasClosed($this->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyRegistered(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->ticketId;
    }

    #[EventSourcingHandler]
    public function applyClosed(TicketWasClosed $event): void
    {
        $this->isClosed = true;
    }
}
```

Key rules:
- Command handlers return `array` of events
- `#[EventSourcingHandler]` rebuilds state (no side effects)
- Use `WithAggregateVersioning` trait for optimistic concurrency

## 2. ProjectionV2

Source: `Ecotone\Projecting\Attribute\ProjectionV2`

Every ProjectionV2 class needs:
1. `#[ProjectionV2('projection_name')]` — class-level, unique name
2. A stream source: `#[FromStream(Ticket::class)]` or `#[FromAggregateStream(Ticket::class)]`
3. At least one `#[EventHandler]` method

```php
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\Attribute\FromStream;
use Ecotone\Modelling\Attribute\EventHandler;

#[ProjectionV2('ticket_list')]
#[FromStream(Ticket::class)]
class TicketListProjection
{
    private array $tickets = [];

    #[EventHandler]
    public function onRegistered(TicketWasRegistered $event): void
    {
        $this->tickets[$event->ticketId] = ['type' => $event->type, 'status' => 'open'];
    }

    #[EventHandler]
    public function onClosed(TicketWasClosed $event): void
    {
        $this->tickets[$event->ticketId]['status'] = 'closed';
    }
}
```

### Stream Sources

```php
// From a named stream
#[ProjectionV2('my_proj'), FromStream(Ticket::class)]

// From an aggregate stream (auto-resolves stream name)
#[ProjectionV2('my_proj'), FromAggregateStream(Order::class)]

// Multiple streams
#[ProjectionV2('calendar'), FromStream(Calendar::class), FromStream(Meeting::class)]
```

### Lifecycle Attributes

| Attribute | When Called |
|-----------|-----------|
| `#[ProjectionInitialization]` | On first run / initialization |
| `#[ProjectionDelete]` | When projection is deleted |
| `#[ProjectionReset]` | When projection is reset |
| `#[ProjectionFlush]` | After each batch of events |

```php
#[ProjectionInitialization]
public function init(): void
{
    // Create tables, setup resources
}

#[ProjectionDelete]
public function delete(): void
{
    // Drop tables, cleanup
}
```

### Execution Modes

**Synchronous (default)** — inline with event production.

**Polling** — on-demand or scheduled:
```php
#[ProjectionV2('my_proj'), Polling('my_proj_endpoint'), FromStream(Ticket::class)]
```

**Streaming** — consumes from a streaming channel:
```php
#[ProjectionV2('my_proj'), Streaming('my_channel'), FromStream(Ticket::class)]
```

### Partitioning

```php
use Ecotone\Projecting\Attribute\Partitioned;

#[ProjectionV2('ticket_list'), Partitioned, FromStream(stream: Ticket::class, aggregateType: Ticket::class)]
```

- Per-aggregate-instance position tracking
- NOT compatible with multiple `#[FromStream]` attributes
- Default partition key: aggregate ID

### Configuration Attributes

```php
// Batch size for event loading
#[ProjectionV2('my_proj'), ProjectionExecution(eventLoadingBatchSize: 500), FromStream(Ticket::class)]

// Backfill configuration
#[ProjectionV2('my_proj'), Partitioned, ProjectionBackfill(backfillPartitionBatchSize: 100, asyncChannelName: 'backfill'), FromStream(Ticket::class)]

// Blue/green deployment
#[ProjectionV2('my_proj'), ProjectionDeployment(live: false), FromStream(Ticket::class)]
#[ProjectionV2('my_proj'), ProjectionDeployment(manualKickOff: true), FromStream(Ticket::class)]
```

### State Management

```php
use Ecotone\EventSourcing\Attribute\ProjectionState;

#[EventHandler]
public function onEvent(TicketWasRegistered $event, #[ProjectionState] array $state = []): array
{
    $state['count'] = ($state['count'] ?? 0) + 1;
    return $state;  // Return to persist
}
```

## 3. Event Store

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

## 4. Event Versioning

### Revision Attribute

```php
use Ecotone\Modelling\Attribute\Revision;

#[Revision(2)]
class PersonWasRegistered
{
    public function __construct(
        public readonly string $personId,
        public readonly string $type  // added in v2
    ) {}
}
```

- Default revision is 1 when no attribute present
- Stored in metadata as `MessageHeaders::REVISION`

### Named Events

```php
use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('ticket.was_registered')]
class TicketWasRegistered { }
```

Decouples class name from stored event type — allows renaming classes safely.

## 5. Testing

### Basic Event-Sourced Testing

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

$events = $ecotone
    ->sendCommand(new RegisterTicket('t-1', 'Bug'))
    ->getRecordedEvents();

$this->assertEquals([new TicketWasRegistered('t-1', 'Bug')], $events);
```

### With Pre-Set Events

```php
$events = $ecotone
    ->withEventsFor('t-1', Ticket::class, [
        new TicketWasRegistered('t-1', 'Bug'),
    ])
    ->sendCommand(new CloseTicket('t-1'))
    ->getRecordedEvents();
```

### With Event Store

```php
$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
    classesToResolve: [Ticket::class],
);
```

### Projection Testing

```php
$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
    classesToResolve: [TicketListProjection::class, Ticket::class],
    containerOrAvailableServices: [new TicketListProjection()],
);

$ecotone->initializeProjection('ticket_list');
$ecotone->sendCommand(new RegisterTicket('t-1', 'Bug'));
$ecotone->triggerProjection('ticket_list');

$result = $ecotone->sendQueryWithRouting('getTickets');
```

### Projection Lifecycle

```php
$ecotone->initializeProjection('name');  // Setup
$ecotone->triggerProjection('name');     // Process events
$ecotone->resetProjection('name');       // Clear + reinit
$ecotone->deleteProjection('name');      // Cleanup
```

## Key Rules

- Prefer `#[ProjectionV2]` over legacy `#[Projection]` for new code
- Partitioned projections cannot use multiple streams
- `#[FromAggregateStream]` requires an `#[EventSourcingAggregate]` class
- Projection names must be unique
- See `references/projection-patterns.md` for detailed examples
- See `references/versioning-patterns.md` for upcasting patterns
