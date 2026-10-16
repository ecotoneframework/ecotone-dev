---
name: ecotone-testing
description: >-
  Writes and debugs tests for Ecotone using EcotoneLite::bootstrapFlowTesting,
  aggregate testing, async-tested-synchronously patterns, projections, and
  common failure diagnosis. Use when writing tests, debugging test failures,
  adding test coverage, or implementing any new feature that needs tests.
  Should be co-triggered whenever a new handler, aggregate, saga, projection,
  or interceptor is being implemented.
---

# Ecotone Testing

## Overview

Ecotone provides `EcotoneLite` for bootstrapping lightweight, in-process test environments.

## 1. Bootstrap Selection

| Method | Use When |
|--------|----------|
| `EcotoneLite::bootstrapFlowTesting()` | Every flow test. Every installed package loads; each runs in memory until the test gives it a connection |

```php
use Ecotone\Api\Lite\EcotoneLite;

// Standard testing
$ecotone = EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [MyHandler::class],
    containerOrAvailableServices: [new MyHandler()],
);

// Event sourcing testing: in memory, as no connection is given; pass DbalConnectionFactory::class => ... to use the database
$ecotone = EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [MyAggregate::class],
);
```

## 2. Core Testing Patterns

### Simple Handler

```php
public function test_handling_command(): void
{
    $handler = new class {
        public bool $called = false;
        #[CommandHandler]
        public function handle(PlaceOrder $command): void
        {
            $this->called = true;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $this->assertTrue($handler->called);
}
```

### Aggregate

```php
public function test_creating_aggregate(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1', 'item-A'));

    $order = $ecotone->getAggregate(Order::class, 'order-1');
    $this->assertEquals('item-A', $order->getItem());
}
```

### Event-Sourced Aggregate with withEventsFor

```php
public function test_closing_ticket(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

    $events = $ecotone
        ->withEventsFor('ticket-1', Ticket::class, [
            new TicketWasRegistered('ticket-1', 'alert'),
        ])
        ->sendCommand(new CloseTicket('ticket-1'))
        ->popRecordedEvents();

    $this->assertEquals([new TicketWasClosed('ticket-1')], $events);
}
```

Seeded events are recorded at the test clock's time. Give history its own time with `at:`, on `withEventsFor()`,
`withEventStream()` and `withEvents()`; the clock returns to where it was afterwards:

```php
$ecotone
    ->withEventsFor('ticket-1', Ticket::class, [new TicketWasRegistered('ticket-1', 'alert')], at: new DateTimeImmutable('2025-01-01 09:00:00'))
    ->withEventsFor('ticket-1', Ticket::class, [new TicketWasEscalated('ticket-1')], aggregateVersion: 1, at: new DateTimeImmutable('2025-01-03 09:00:00'));
```

### Async-Tested-Synchronously

`bootstrapFlowTesting()` gives every `#[Asynchronous('x')]` channel you did not configure an in-memory, delayable queue.
Handlers never run inline: consume the channel with `run()`. Register a channel yourself only when the test needs a
different one (e.g. `SimpleMessageChannelBuilder::createQueueChannel('x', delayable: false)`); it replaces the provided one.

```php
public function test_async_handler(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [NotificationHandler::class],
        containerOrAvailableServices: [new NotificationHandler()],
    );

    $ecotone->sendCommand(new SendNotification('hello'));   // queued on 'notifications', not processed yet

    $ecotone->run('notifications');                          // handles up to 100 due messages
}
```

### Time and Delayed Messages

Pin the clock at bootstrap, so the first message the test sends is already stamped deterministically:

```php
$ecotone = EcotoneLite::bootstrapFlowTesting(
    [OrderExpiry::class],
    [new OrderExpiry()],                                                                   // #[Delayed(hours: 24)]
    testConfiguration: TestConfiguration::createWithDefaults()->withTimeAt(new DateTimeImmutable('2026-03-01 12:00:00')),
);
$ecotone->publishEvent(new OrderPlaced('order-1'))->run('async');   // not due yet

$ecotone->advanceTimeBy(TimeSpan::withHours(24))->run('async');     // due now, handled
$ecotone->now();                                                    // DatePoint 2026-03-02 12:00:00
```

- One clock stamps everything: message timestamps, delayed and retried deliveries, dead letters, scheduled endpoints,
  and the event store's `created_at`, on in-memory and DBAL channels alike. The pattern above is the proof for a delay
  on a `DbalBackedMessageChannelBuilder` channel too: `run()` before the advance shows nothing handled, after it shows
  the handler ran.
- Without `withTimeAt()` the clock runs on real time until the first `changeTimeTo()` or `advanceTimeBy()`.
- The test clock stays where the test put it: handlers inside `run()` see exactly that time, and `run()` never moves it.
- `advanceTimeBy()` calls add up; `changeTimeTo()` accepts the same or a later instant.
- `run()` delivers what is due at the clock's current time, earliest due first. It has no time argument.
- `now()` returns the clock's current `DatePoint`, for an assertion such as "expires 24 hours from now".
- A service that reads time takes `EcotoneClockInterface` as a dependency; the test bootstrap provides the pinned one.

### Recorded Messages

`popRecordedEvents()` / `popRecordedCommands()` return what was recorded since the last pop **and remove it**; a second
call returns `[]`. `popRecordedEventsOfType(OrderPlaced::class)` removes and returns only that class, leaving the rest.
Events the test publishes itself are recorded too. Aggregates using `WithEvents` expose the same `popRecordedEvents()`.

### Failures, Retries and Dead Letter

- `run()` rethrows a handler exception by default; pass `ExecutionPollingMetadata::createWithTestingSetup(stopOnError: false)`
  to let the error channel, retries and dead letter handle it.
- Asynchronous endpoints retry instantly 3 times by default before the error channel is used
  (`InstantRetryConfiguration::createWithDefaults()->withAsynchronousEndpointsRetry(false)` turns it off).
- Delayed retries are delayed messages. Use `RetryTemplateBuilder::fixedBackOff(0)->maxRetries(3)` in tests so one
  `run()` walks a message through all retries into the dead letter. `maxRetries(3)` = 1 initial delivery + 3 retries.

### Service Stubs

```php
public function test_with_service_dependency(): void
{
    $mailer = new InMemoryMailer();

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [OrderHandler::class],
        containerOrAvailableServices: [
            new OrderHandler($mailer),
            OrderRepository::class => new InMemoryOrderRepository(),
        ],
    );

    $ecotone->sendCommand(new PlaceOrder('123'));
    $this->assertCount(1, $mailer->getSentEmails());
}
```

## 3. Debugging Test Failures

| Symptom | Cause | Fix |
|---------|-------|-----|
| "It is handled by X::y(), which is not registered in this Ecotone Lite bootstrap" | Handler class not in `classesToResolve` | Add the named class, or `ServiceConfiguration::withNamespaces([...])` |
| "No Command Handler is registered for it in this Ecotone Lite bootstrap" | No handler exists | Add the attribute the message names |
| "Service not found in container" | Missing dependency | Add to `containerOrAvailableServices` |
| Message not processed | Async handler not run, or delay not reached | Call `$ecotone->run('channelName')`; move time with `advanceTimeBy()` first |
| "Cannot move time backwards" | `changeTimeTo()` earlier than the clock | Request a later time or use `advanceTimeBy()` |
| "... which cannot be instantiated ... Type the parameter with a concrete class" | Interface-typed parameter without type information | Send an object (not an array) or type the parameter with a concrete class/union |
| "If $x is a service rather than the message payload, mark it with #[Reference]" | First parameter without attribute is the payload | Add `#[Reference]` to the service parameter |
| "Aggregate ... was not found ... was sent by X::y() while handling ..." | A handler reacting to another message sent the command | Seed the aggregate or register only the classes the test needs |
| "Module not found" | Wrong `ModulePackageList` config | Check `withEnabledModulePackageNames([...])` lists the needed packages |
| Database errors | Missing DSN env vars | Run inside Docker container with env vars set |
| Lowest dependency failures | API differences between versions | Test both `--prefer-lowest` and latest |

## Key Rules

- Use `EcotoneLite::bootstrapFlowTesting()` as the starting point
- Pass handler instances via `containerOrAvailableServices`
- Event sourcing needs nothing extra: the event store is in memory unless the test is given a `DbalConnectionFactory`

## Additional resources

- [API reference](references/api-reference.md) -- Full `EcotoneLite` bootstrap method signatures (`bootstrapFlowTesting`, `bootstrap`) and complete `FlowTestSupport` API including all `send*`, `publish*`, `run()`, `getAggregate()`, `getSaga()`, `popRecordedEvents()`, `popRecordedEventHeaders()`, projection methods, time control, and infrastructure methods. Load when you need exact method signatures, parameter types, or available options.

- [Usage examples](references/usage-examples.md) -- Complete test implementations for all patterns: event handler testing, query handler testing, state-stored and event-sourced aggregate testing, projection testing with inline classes, service stubs with dependencies, recorded messages inspection, and `ModulePackageList` configuration with all available package constants. Load when you need full copy-paste test examples or advanced testing patterns.

- [Testing patterns](references/testing-patterns.md) -- Async-tested-synchronously patterns with `SimpleMessageChannelBuilder` and `ExecutionPollingMetadata`, projection testing, and the debugging/failure diagnosis reference table. Load when testing async handlers, projections, or diagnosing test failures.
