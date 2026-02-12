---
name: ecotone-aggregate
description: >-
  Creates DDD aggregates following Ecotone patterns: state-stored and
  event-sourced variants with proper identifier mapping, factory patterns,
  and command handler wiring. Use when creating aggregates, entities with
  command handlers, or domain models.
---

# Ecotone Aggregates

## 1. State-Stored Aggregate

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;
    private string $product;
    private bool $cancelled = false;

    // Static factory — creates new aggregate
    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->orderId = $command->orderId;
        $order->product = $command->product;
        return $order;
    }

    // Instance method — modifies existing aggregate
    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        $this->cancelled = true;
    }

    #[QueryHandler]
    public function getStatus(GetOrderStatus $query): string
    {
        return $this->cancelled ? 'cancelled' : 'active';
    }
}
```

Key rules:
- `#[Aggregate]` on the class
- `#[Identifier]` on the identity property
- Static factory `#[CommandHandler]` returns `self` for creation
- Instance `#[CommandHandler]` for state changes (no `self` return needed)

## 2. Event-Sourced Aggregate

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
    private string $type;
    private bool $isClosed = false;

    // Factory returns array of events
    #[CommandHandler]
    public static function register(RegisterTicket $command): array
    {
        return [new TicketWasRegistered($command->ticketId, $command->type)];
    }

    // Action returns array of events
    #[CommandHandler]
    public function close(CloseTicket $command): array
    {
        if ($this->isClosed) {
            return [];
        }
        return [new TicketWasClosed($this->ticketId)];
    }

    // Event sourcing handlers rebuild state from events
    #[EventSourcingHandler]
    public function applyRegistered(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->ticketId;
        $this->type = $event->type;
    }

    #[EventSourcingHandler]
    public function applyClosed(TicketWasClosed $event): void
    {
        $this->isClosed = true;
    }
}
```

Key rules:
- `#[EventSourcingAggregate]` on the class
- Command handlers return `array` of event objects
- `#[EventSourcingHandler]` applies events to rebuild state (no side effects)
- Use `WithAggregateVersioning` trait for optimistic concurrency
- Factory (static) returns events; framework calls `#[EventSourcingHandler]` methods automatically

## 3. Identifier Mapping

### Simple Identifier

Command property matching the aggregate identifier name is auto-resolved:

```php
// Command
class CancelOrder
{
    public function __construct(public readonly string $orderId) {}
}

// Aggregate — $orderId matches automatically
#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;
}
```

### TargetIdentifier on Command

Explicitly mark which command property maps to the aggregate ID:

```php
use Ecotone\Modelling\Attribute\TargetIdentifier;

class CancelOrder
{
    public function __construct(
        #[TargetIdentifier] public readonly string $orderId
    ) {}
}
```

### IdentifierMapping on Handler

Map a differently-named command property:

```php
#[CommandHandler(identifierMapping: ['orderId' => 'id'])]
public function cancel(CancelOrder $command): void { }
```

### Multiple Identifiers

```php
#[Aggregate]
class ShelfItem
{
    #[Identifier]
    private string $warehouseId;

    #[Identifier]
    private string $productId;
}
```

## 4. Factory Patterns

### State-Stored Factory

```php
#[CommandHandler]
public static function create(CreateOrder $command): self
{
    $order = new self();
    $order->orderId = $command->orderId;
    $order->product = $command->product;
    return $order;
}
```

### Event-Sourced Factory

```php
#[CommandHandler]
public static function create(CreateTicket $command): array
{
    return [new TicketWasCreated($command->ticketId, $command->title)];
}
```

Factory methods are **static** because there is no existing aggregate instance yet.

## 5. Testing

### State-Stored

```php
public function test_order_placement(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1', 'Widget'));

    $order = $ecotone->getAggregate(Order::class, 'order-1');
    $this->assertEquals('Widget', $order->getProduct());
}
```

### Event-Sourced

```php
public function test_ticket_close(): void
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

### Event-Sourced with Event Store

```php
public function test_with_event_store(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        classesToResolve: [Ticket::class],
    );

    $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Bug'));
    $events = $ecotone->getRecordedEvents();

    $this->assertEquals([new TicketWasRegistered('ticket-1', 'Bug')], $events);
}
```

## Key Rules

- Factory (creation) handlers are always `static`
- State-stored factories return `self`, event-sourced factories return `array`
- `#[EventSourcingHandler]` methods have NO side effects — only state assignment
- Use `WithAggregateVersioning` for event-sourced aggregates
- Command properties matching `#[Identifier]` field names are auto-resolved

## Additional resources

- [Aggregate patterns reference](references/aggregate-patterns.md) — Complete aggregate implementations including full state-stored and event-sourced classes, `WithEvents` trait usage, `#[EventSourcingHandler]` apply methods, `WithAggregateVersioning` for optimistic locking, Doctrine ORM integration, and EcotoneLite testing patterns. Load when implementing a new aggregate or need full class definitions with imports.
