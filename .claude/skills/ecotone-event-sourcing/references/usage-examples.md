# Event Sourcing Usage Examples

## Projection Examples

### Basic ProjectionV2 with Lifecycle

```php
use Ecotone\Projecting\Attribute\ProjectionV2;
use Ecotone\Projecting\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[ProjectionV2('ticket_list')]
#[FromStream(Ticket::class)]
class TicketListProjection
{
    private array $tickets = [];

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->tickets = [];
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->tickets = [];
    }

    #[EventHandler]
    public function onRegistered(TicketWasRegistered $event): void
    {
        $this->tickets[$event->ticketId] = [
            'id' => $event->ticketId,
            'type' => $event->type,
            'status' => 'open',
        ];
    }

    #[EventHandler]
    public function onClosed(TicketWasClosed $event): void
    {
        $this->tickets[$event->ticketId]['status'] = 'closed';
    }

    #[QueryHandler('getTickets')]
    public function getAll(): array
    {
        return array_values($this->tickets);
    }

    #[QueryHandler('getTicket')]
    public function getById(string $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
    }
}
```

### Partitioned Projection with State

```php
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionState;

#[Partitioned]
#[ProjectionV2('ticket_details')]
#[FromStream(stream: Ticket::class, aggregateType: Ticket::class)]
class TicketDetailsProjection
{
    #[EventHandler]
    public function onRegistered(
        TicketWasRegistered $event,
        #[ProjectionState] array $state = []
    ): array {
        $state['ticketId'] = $event->ticketId;
        $state['type'] = $event->type;
        $state['status'] = 'open';
        return $state;
    }

    #[EventHandler]
    public function onClosed(
        TicketWasClosed $event,
        #[ProjectionState] array $state = []
    ): array {
        $state['status'] = 'closed';
        return $state;
    }
}
```

Partitioned projection rules:
- Each aggregate ID gets independent position tracking
- Cannot use multiple `#[FromStream]` attributes
- Default partition key is `MessageHeaders::EVENT_AGGREGATE_ID`
- Custom key: `#[Partitioned('custom_header')]`

### Polling Projection

```php
use Ecotone\Projecting\Attribute\Polling;

#[Polling('orderSummaryEndpoint')]
#[ProjectionV2('order_summary')]
#[FromStream(Order::class)]
class OrderSummaryProjection
{
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Process on-demand when triggered
    }
}
```

Trigger in tests:
```php
$ecotone->triggerProjection('order_summary');
// Or run the endpoint directly:
$ecotone->run('orderSummaryEndpoint', ExecutionPollingMetadata::createWithTestingSetup());
```

### Streaming Projection

```php
use Ecotone\Projecting\Attribute\Streaming;

#[Streaming('dashboard_channel')]
#[ProjectionV2('live_dashboard')]
#[FromStream(Order::class)]
class LiveDashboardProjection
{
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Continuously processes events from the streaming channel
    }
}
```

### Multi-Stream Projection

```php
#[ProjectionV2('calendar_view')]
#[FromStream(Calendar::class)]
#[FromStream(Meeting::class)]
class CalendarViewProjection
{
    #[EventHandler]
    public function onCalendarCreated(CalendarWasCreated $event): void { }

    #[EventHandler]
    public function onMeetingScheduled(MeetingWasScheduled $event): void { }
}
```

Cannot be combined with `#[Partitioned]`.

### FromAggregateStream

```php
use Ecotone\Projecting\Attribute\FromAggregateStream;

#[ProjectionV2('order_list')]
#[FromAggregateStream(Order::class)]
class OrderListProjection
{
    // Automatically resolves stream name from the aggregate class
    // Requires Order to be an #[EventSourcingAggregate]
}
```

### Projection with EventStreamEmitter

```php
use Ecotone\EventSourcing\EventStreamEmitter;

#[ProjectionV2('notifications')]
#[FromStream(Order::class)]
class NotificationProjection
{
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event, EventStreamEmitter $emitter): void
    {
        $emitter->linkTo('notification_stream', [
            new NotificationRequested($event->orderId, 'Order placed'),
        ]);

        // Or emit to projection's own stream:
        $emitter->emit([new OrderListUpdated($event->orderId)]);
    }
}
```

### Configuration Attributes

```php
use Ecotone\Projecting\Attribute\ProjectionExecution;
use Ecotone\Projecting\Attribute\ProjectionBackfill;
use Ecotone\Projecting\Attribute\ProjectionDeployment;

// Batch size for event loading
#[ProjectionV2('big_projection')]
#[ProjectionExecution(eventLoadingBatchSize: 500)]
#[FromStream(Ticket::class)]
class BigProjection { }

// Backfill configuration
#[ProjectionV2('my_proj')]
#[Partitioned]
#[ProjectionBackfill(backfillPartitionBatchSize: 100, asyncChannelName: 'backfill_channel')]
#[FromStream(Ticket::class)]
class BackfillableProjection { }

// Blue/green deployment: non-live suppresses EventStreamEmitter events
#[ProjectionV2('projection_v2')]
#[ProjectionDeployment(live: false)]
#[FromStream(Ticket::class)]
class ProjectionV2Deploy { }

// Manual kickoff: requires explicit initialization
#[ProjectionV2('projection_v1')]
#[ProjectionDeployment(manualKickOff: true)]
#[FromStream(Ticket::class)]
class ProjectionV1Deploy { }
```

## Event Versioning Examples

### Revision and NamedEvent

```php
use Ecotone\Modelling\Attribute\Revision;
use Ecotone\Modelling\Attribute\NamedEvent;

// Version 1 (default when no attribute)
class PersonWasRegistered
{
    public function __construct(
        public readonly string $personId,
        public readonly string $name,
    ) {}
}

// Version 2 -- added 'type' field
#[Revision(2)]
class PersonWasRegistered
{
    public function __construct(
        public readonly string $personId,
        public readonly string $name,
        public readonly string $type,  // new in v2
    ) {}
}

// Named event decouples class name from stored type
#[NamedEvent('ticket.was_registered')]
class TicketWasRegistered
{
    public function __construct(
        public readonly string $ticketId,
        public readonly string $type,
    ) {}
}
```

### Upcasting Pattern

Upcasters transform old event versions to the current schema:

```php
use Ecotone\Modelling\Attribute\EventRevision;

class PersonWasRegisteredUpcaster
{
    public function upcast(array $payload, int $revision): array
    {
        if ($revision < 2) {
            $payload['type'] = 'default';  // Provide default for new field
        }
        return $payload;
    }
}
```

### Event Schema Evolution Strategies

**Adding Fields (Backward Compatible):**
```php
// v1: { personId, name }
// v2: { personId, name, type }
// Upcaster sets type='default' for v1 events
```

**Renaming Fields:**
```php
public function upcast(array $payload, int $revision): array
{
    if ($revision < 2) {
        $payload['fullName'] = $payload['name'];
        unset($payload['name']);
    }
    return $payload;
}
```

**Splitting Events:**
```php
// v1: PersonWasRegisteredAndActivated { id, name, activatedAt }
// v2: Split into PersonWasRegistered + PersonWasActivated
```

**Removing Fields:**
```php
public function upcast(array $payload, int $revision): array
{
    unset($payload['deprecatedField']);
    return $payload;
}
```

### Versioning Best Practices

1. **Always increment revision** when changing event schema
2. **Never modify stored events** -- transform on read via upcasters
3. **Use `#[NamedEvent]`** to decouple storage from class names
4. **Add defaults in upcasters** for new required fields
5. **Keep events immutable** -- all properties `readonly`
6. **Version from the start** -- use `#[Revision(1)]` explicitly
7. **Test upcasters** -- verify old events can be loaded with new code

## Dynamic Consistency Boundary (DCB)

DCB allows multiple aggregates to share consistency guarantees without distributed transactions:

```php
#[ProjectionV2('inventory_consistency')]
#[FromStream(Order::class)]
#[FromStream(Warehouse::class)]
class InventoryConsistencyProjection
{
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Check inventory consistency across aggregates
    }

    #[EventHandler]
    public function onStockUpdated(StockWasUpdated $event): void
    {
        // Update inventory view
    }
}
```

- Events from multiple aggregates can be read in a single projection
- Projection state provides the consistency boundary
- Use multi-stream projections (`#[FromStream]` on multiple aggregate types)
- Decision models can load events from multiple streams to make consistent decisions
