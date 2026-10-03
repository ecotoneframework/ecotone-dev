# Event Sourcing Testing Patterns

## Basic Event-Sourced Aggregate Testing

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

$events = $ecotone
    ->sendCommand(new RegisterTicket('t-1', 'Bug'))
    ->popRecordedEvents();

$this->assertEquals([new TicketWasRegistered('t-1', 'Bug')], $events);
```

## Testing with Pre-Set Events

Use `withEventsFor()` to set up initial aggregate state from events:

```php
$events = $ecotone
    ->withEventsFor('t-1', Ticket::class, [
        new TicketWasRegistered('t-1', 'Bug'),
    ])
    ->sendCommand(new CloseTicket('t-1'))
    ->popRecordedEvents();

$this->assertEquals([new TicketWasClosed('t-1')], $events);
```

## Testing with Event Store

```php
$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
    classesToResolve: [Ticket::class],
);
```

## Projection Testing (Command-Driven)

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

## Projection Testing with withEventStream (No Aggregate Needed)

Use `withEventStream` to append events directly to a stream, bypassing the need for an Aggregate. This is useful when testing projections in isolation.

```php
use Ecotone\Api\EventSourcing\Event;

public function test_projection_with_direct_events(): void
{
    $projection = new TicketListProjection();

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [TicketListProjection::class],
        containerOrAvailableServices: [$projection],
    );

    $ecotone->initializeProjection('ticket_list');

    // Append events directly to the stream -- no Aggregate required
    $ecotone->withEventStream('ecotone_event_stream', [
        Event::create(new TicketWasRegistered('t-1', 'Bug')),
        Event::create(new TicketWasRegistered('t-2', 'Feature')),
        Event::create(new TicketWasClosed('t-1')),
    ]);

    $ecotone->triggerProjection('ticket_list');

    $tickets = $ecotone->sendQueryWithRouting('getTickets');
    $this->assertCount(2, $tickets);
    $this->assertSame('closed', $ecotone->sendQueryWithRouting('getTicket', metadata: ['ticketId' => 't-1'])['status']);
}
```

Key points:
- Use `bootstrapFlowTesting` (no EventStore bootstrap needed) -- the in-memory event store is registered automatically
- Stream name in `withEventStream` must match the stream the projection reads: `'ecotone_event_stream'` unless the
  aggregate declares `#[Stream('...')]`
- Wrap each event in `Event::create()` from `Ecotone\Api\EventSourcing\Event`
- No Aggregate class is registered in `classesToResolve`

## Projection Lifecycle Methods

```php
$ecotone->initializeProjection('name');  // Setup
$ecotone->triggerProjection('name');     // Process pending events synchronously, immediately
$ecotone->resetProjection('name');       // Delete + reinit + replay full history
$ecotone->deleteProjection('name');      // Cleanup
```

### Rebuild vs Backfill

`triggerProjection()` calls `executeAll()` -- it reads directly from the stream source and processes pending events synchronously, immediately, in the same call. `resetProjection()` calls `executeAllWithReset()` (delete + init + `executeAll()`), so it replays full history, not just what's pending.

To test rebuild behavior (emissions suppressed), run the `ecotone:projection:rebuild` console command:

```php
// Rebuild: replays events but suppresses EventStreamEmitter emissions
$ecotone->runConsoleCommand('ecotone:projection:rebuild', ['name' => 'projection_name']);
```

Backfill and delete work the same way, through `ecotone:projection:backfill` and `ecotone:projection:delete`.

## Testing Revision-Aware Event Sourcing Handlers

There is no framework-invoked "upcaster" -- test that the `#[EventSourcingHandler]` reads the injected
`#[Header(MessageHeaders::REVISION)]` correctly. Injecting that header requires an Enterprise licence; without
one, bootstrap accepts the class but `sendCommand()` throws `Ecotone\Messaging\Support\InvalidArgumentException`.

```php
use Ecotone\Test\LicenceTesting;

public function test_event_sourcing_handler_receives_revision_header(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [Person::class],
        licenceKey: LicenceTesting::VALID_LICENCE,
    );

    $ecotone->sendCommand(new RegisterPerson('person-1', 'premium'));

    $person = $ecotone->getAggregate(Person::class, 'person-1');
    $this->assertEquals(2, $person->getRegisteredWithRevision());
}
```
