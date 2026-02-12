# Projection Patterns Reference

## Basic ProjectionV2

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

## Partitioned Projection

```php
use Ecotone\Projecting\Attribute\Partitioned;
use Ecotone\Projecting\Attribute\FromStream;
use Ecotone\EventSourcing\Attribute\ProjectionState;

#[ProjectionV2('ticket_details')]
#[Partitioned]
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

## Polling Projection

```php
use Ecotone\Projecting\Attribute\Polling;

#[ProjectionV2('order_summary')]
#[Polling('orderSummaryEndpoint')]
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

## Streaming Projection

```php
use Ecotone\Projecting\Attribute\Streaming;

#[ProjectionV2('live_dashboard')]
#[Streaming('dashboard_channel')]
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

## Multi-Stream Projection

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

## FromAggregateStream

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

## Projection with Event Stream Emitter

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

## Configuration Attributes

### ProjectionExecution

```php
use Ecotone\Projecting\Attribute\ProjectionExecution;

#[ProjectionV2('big_projection')]
#[ProjectionExecution(eventLoadingBatchSize: 500)]
#[FromStream(Ticket::class)]
class BigProjection { }
```

### ProjectionBackfill

```php
use Ecotone\Projecting\Attribute\ProjectionBackfill;

#[ProjectionV2('my_proj')]
#[Partitioned]
#[ProjectionBackfill(backfillPartitionBatchSize: 100, asyncChannelName: 'backfill_channel')]
#[FromStream(Ticket::class)]
class BackfillableProjection { }
```

### ProjectionDeployment (Blue/Green)

```php
use Ecotone\Projecting\Attribute\ProjectionDeployment;

// Non-live: EventStreamEmitter events are suppressed
#[ProjectionV2('new_proj')]
#[ProjectionDeployment(live: false)]
#[FromStream(Ticket::class)]
class NewProjection { }

// Manual kickoff: requires explicit initialization
#[ProjectionV2('manual_proj')]
#[ProjectionDeployment(manualKickOff: true)]
#[FromStream(Ticket::class)]
class ManualProjection { }
```

## Testing Projections

```php
public function test_projection(): void
{
    $projection = new TicketListProjection();

    $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        classesToResolve: [TicketListProjection::class, Ticket::class],
        containerOrAvailableServices: [$projection],
    );

    // Initialize
    $ecotone->initializeProjection('ticket_list');

    // Produce events via commands
    $ecotone->sendCommand(new RegisterTicket('t-1', 'Bug'));
    $ecotone->sendCommand(new RegisterTicket('t-2', 'Feature'));

    // Trigger projection to process events
    $ecotone->triggerProjection('ticket_list');

    // Query read model
    $tickets = $ecotone->sendQueryWithRouting('getTickets');
    $this->assertCount(2, $tickets);

    // Test reset
    $ecotone->resetProjection('ticket_list');
    $ecotone->triggerProjection('ticket_list');
    $tickets = $ecotone->sendQueryWithRouting('getTickets');
    $this->assertCount(2, $tickets);  // Rebuilt from events
}
```

## Validation Rules

1. `#[Partitioned]` + multiple `#[FromStream]` → ConfigurationException
2. `#[FromAggregateStream]` requires `#[EventSourcingAggregate]` class
3. `#[Polling]` + `#[Streaming]` → not allowed
4. `#[Polling]` + `#[Partitioned]` → not allowed
5. `#[Partitioned]` + `#[Streaming]` → not allowed
6. Projection names must be unique
7. Backfill batch size must be ≥ 1
