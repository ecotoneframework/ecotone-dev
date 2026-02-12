---
name: ecotone-testing
description: >-
  Writes and debugs tests for Ecotone using EcotoneLite::bootstrapFlowTesting,
  inline anonymous classes, and snake_case methods. Covers handler testing,
  aggregate testing, async-tested-synchronously patterns, projections, and
  common failure diagnosis. Use when writing tests, debugging test failures,
  adding test coverage, or implementing any new feature that needs tests.
  Should be co-triggered whenever a new handler, aggregate, saga, projection,
  or interceptor is being implemented.
---

# Ecotone Testing

## Overview

Ecotone provides `EcotoneLite` for bootstrapping lightweight, in-process test environments. Tests use inline anonymous classes with PHP 8.1+ attributes, snake_case method names, and high-level behavioral assertions. Use this skill when writing or debugging any Ecotone test.

## 1. Bootstrap Selection

| Method | Use When |
|--------|----------|
| `EcotoneLite::bootstrapFlowTesting()` | Standard handler/aggregate tests |
| `EcotoneLite::bootstrapFlowTestingWithEventStore()` | Event-sourced aggregate and projection tests |

```php
use Ecotone\Lite\EcotoneLite;

// Standard testing
$ecotone = EcotoneLite::bootstrapFlowTesting(
    classesToResolve: [MyHandler::class],
    containerOrAvailableServices: [new MyHandler()],
);

// Event sourcing testing
$ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
    classesToResolve: [MyAggregate::class],
);
```

## 2. Test Structure Rules

- **`snake_case`** method names (enforced by PHP-CS-Fixer)
- **High-level tests** from end-user perspective -- never test internals
- **Inline anonymous classes** with PHP 8.1+ attributes -- not separate fixture files
- **No comments** -- descriptive method names only
- **Licence header** on every test file

```php
/**
 * licence Apache-2.0
 */
final class OrderTest extends TestCase
{
    public function test_placing_order_records_event(): void
    {
        // test body
    }
}
```

## 3. Core Testing Patterns

### Simple Handler

```php
public function test_handling_command(): void
{
    $handler = new #[CommandHandler] class {
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
        ->getRecordedEvents();

    $this->assertEquals([new TicketWasClosed('ticket-1')], $events);
}
```

### Async-Tested-Synchronously

```php
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\ExecutionPollingMetadata;

public function test_async_handler(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [NotificationHandler::class],
        containerOrAvailableServices: [new NotificationHandler()],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('notifications'),
        ],
    );

    $ecotone->sendCommand(new SendNotification('hello'));

    // Message is queued, not yet processed
    $ecotone->run('notifications', ExecutionPollingMetadata::createWithTestingSetup());

    // Now it's processed
}
```

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

## 4. Debugging Test Failures

| Symptom | Cause | Fix |
|---------|-------|-----|
| "No handler found for message" | Handler class not in `classesToResolve` | Add class to first argument |
| "Service not found in container" | Missing dependency | Add to `containerOrAvailableServices` |
| "Channel not found" | Async channel not configured | Add channel to `enableAsynchronousProcessing` |
| Message not processed | Async handler not run | Call `$ecotone->run('channelName')` |
| "Module not found" | Wrong `ModulePackageList` config | Check `allPackagesExcept()` includes needed modules |
| Database errors | Missing DSN env vars | Run inside Docker container with env vars set |
| Lowest dependency failures | API differences between versions | Test both `--prefer-lowest` and latest |

## 5. Common Mistakes

- **Don't** use raw PHPUnit mocking instead of EcotoneLite -- use the framework's test support
- **Don't** create separate fixture class files for test-only handlers -- use inline anonymous classes
- **Don't** test implementation details -- test behavior from the end-user perspective
- **Don't** forget to call `->run('channel')` for async handlers -- messages won't process otherwise
- **Don't** mix `bootstrapFlowTesting` and `bootstrapFlowTestingWithEventStore` -- pick the right one

## Key Rules

- Every test method name MUST be `snake_case`
- Use `EcotoneLite::bootstrapFlowTesting()` as the starting point
- Pass handler instances via `containerOrAvailableServices`
- For event sourcing, use `bootstrapFlowTestingWithEventStore()`

## Additional resources

- [API reference](references/api-reference.md) -- Full `EcotoneLite` bootstrap method signatures (`bootstrapFlowTesting`, `bootstrapFlowTestingWithEventStore`, `bootstrapForTesting`) and complete `FlowTestSupport` API including all `send*`, `publish*`, `run()`, `getAggregate()`, `getSaga()`, `getRecordedEvents()`, `getRecordedEventHeaders()`, projection methods, time control, and infrastructure methods. Load when you need exact method signatures, parameter types, or available options.

- [Usage examples](references/usage-examples.md) -- Complete test implementations for all patterns: event handler testing, query handler testing, state-stored and event-sourced aggregate testing, projection testing with inline classes, service stubs with dependencies, recorded messages inspection, and `ModulePackageList` configuration with all available package constants. Load when you need full copy-paste test examples or advanced testing patterns.

- [Testing patterns](references/testing-patterns.md) -- Async-tested-synchronously patterns with `SimpleMessageChannelBuilder` and `ExecutionPollingMetadata`, projection testing with `bootstrapFlowTestingWithEventStore`, and the debugging/failure diagnosis reference table. Load when testing async handlers, projections, or diagnosing test failures.
