---
name: ecotone-handler
description: >-
  Creates Ecotone message handlers with PHP attributes, proper
  endpointId configuration, and routing patterns. Covers CommandHandler,
  EventHandler, QueryHandler, and message metadata.
  Use when creating or modifying message handlers.
---

# Ecotone Message Handlers

## 1. Handler Types

| Attribute | Purpose | Returns |
|-----------|---------|---------|
| `#[CommandHandler]` | Handles commands (write operations) | `void` or identifier |
| `#[EventHandler]` | Reacts to events (side effects) | `void` |
| `#[QueryHandler]` | Handles queries (read operations) | Data |
| `#[ServiceActivator]` | Low-level message endpoint | Varies |

## 2. CommandHandler

```php
use Ecotone\Modelling\Attribute\CommandHandler;

class OrderService
{
    #[CommandHandler]
    public function placeOrder(PlaceOrder $command): void
    {
        // handle command
    }
}
```

Constructor parameters:
- `routingKey` (string) — for string-based routing: `#[CommandHandler('order.place')]`
- `endpointId` (string) — unique identifier for this endpoint
- `outputChannelName` (string) — channel to send result to
- `dropMessageOnNotFound` (bool) — drop instead of throwing if aggregate not found
- `identifierMetadataMapping` (array) — map metadata to aggregate identifier
- `identifierMapping` (array) — map command properties to aggregate identifier

### On Aggregates

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;

    // Static factory — creates new aggregate
    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->orderId = $command->orderId;
        return $order;
    }

    // Instance method — modifies existing aggregate
    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        // modify state
    }
}
```

## 3. EventHandler

```php
use Ecotone\Modelling\Attribute\EventHandler;

class NotificationService
{
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // react to event
    }
}
```

Constructor parameters:
- `routingKey` (string) — for `listenTo` routing: `#[EventHandler('order.*')]`
- `endpointId` (string) — unique identifier
- `outputChannelName` (string) — channel for output
- `dropMessageOnNotFound` (bool) — drop if aggregate not found

### Multiple Handlers for Same Event

Multiple `#[EventHandler]` methods can listen to the same event — all will be called.

## 4. QueryHandler

```php
use Ecotone\Modelling\Attribute\QueryHandler;

class OrderQueryService
{
    #[QueryHandler]
    public function getOrder(GetOrder $query): OrderDTO
    {
        return $this->repository->find($query->orderId);
    }
}
```

Constructor parameters:
- `routingKey` (string) — for string-based routing: `#[QueryHandler('order.get')]`
- `endpointId` (string) — unique identifier
- `outputChannelName` (string) — channel for output

## 5. ServiceActivator

Low-level message handler that works directly with message channels:

```php
use Ecotone\Messaging\Attribute\ServiceActivator;

class MessageProcessor
{
    #[ServiceActivator(inputChannelName: 'processChannel')]
    public function process(string $payload): string
    {
        return strtoupper($payload);
    }
}
```

Constructor parameters:
- `inputChannelName` (string, required) — channel to consume from
- `endpointId` (string) — unique identifier
- `outputChannelName` (string) — channel to send result to
- `changingHeaders` (bool) — whether this changes message headers

## 6. Message Metadata with Headers

Access message headers via `#[Header]` parameter attribute:

```php
use Ecotone\Messaging\Attribute\Parameter\Header;

class AuditHandler
{
    #[EventHandler]
    public function audit(
        OrderWasPlaced $event,
        #[Header('timestamp')] int $timestamp,
        #[Header('userId')] string $userId
    ): void {
        // use metadata
    }
}
```

## 7. Routing Patterns

### Class-Based (Default)

The message class type-hint determines routing automatically:

```php
// This handler handles PlaceOrder messages
#[CommandHandler]
public function handle(PlaceOrder $command): void { }
```

### Routing Key (String-Based)

Use when sending messages by name rather than object:

```php
#[CommandHandler('order.place')]
public function handle(array $payload): void { }
```

Send with:
```php
$commandBus->sendWithRouting('order.place', ['orderId' => '123']);
```

### When to Use Which

- **Class-based**: Type-safe, IDE-friendly, preferred for commands/queries
- **Routing key**: Flexible, for integration scenarios, distributed systems

## 8. EndpointId Rules

- Every handler needs a unique `endpointId` when used with async processing or polling
- Naming convention: `'{context}.{action}'` e.g., `'order.place'`, `'notification.send'`
- The `endpointId` connects the handler to channel configuration and monitoring

```php
#[CommandHandler(endpointId: 'order.place')]
#[Asynchronous('orders')]
public function placeOrder(PlaceOrder $command): void { }
```

## 8. Testing Handlers

```php
use Ecotone\Lite\EcotoneLite;

public function test_command_handler(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderService::class],
        [new OrderService()],
    );

    $ecotone->sendCommand(new PlaceOrder('order-1', 'product-1'));

    $this->assertEquals(
        new OrderDTO('order-1', 'product-1', 'placed'),
        $ecotone->sendQuery(new GetOrder('order-1'))
    );
}

public function test_command_handler_with_routing_key(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderService::class],
        [new OrderService()],
    );

    $ecotone->sendCommandWithRoutingKey('order.place', ['orderId' => '123']);

    $this->assertEquals('123', $ecotone->sendQueryWithRouting('order.get', metadata: ['aggregate.id' => '123']));
}

public function test_event_handler_is_called(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [NotificationService::class],
        [$handler = new NotificationService()],
    );

    $ecotone->publishEvent(new OrderWasPlaced('order-1'));

    $this->assertTrue($handler->wasNotified());
}

public function test_recorded_events(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [Order::class],
    );

    $events = $ecotone
        ->sendCommand(new PlaceOrder('order-1', 'product-1'))
        ->getRecordedEvents();

    $this->assertEquals([new OrderWasPlaced('order-1')], $events);
}
```

## Key Rules

- First parameter is the message object (type-hinted)
- `#[CommandHandler]` on aggregates: static = factory (creation), instance = action (modification)
- Use `#[Header]` for metadata access, not message wrapping
- PHPDoc `@param`/`@return` on public API methods
- No comments — meaningful method names only

## Additional resources

- [Handler patterns reference](references/handler-patterns.md) — Complete handler implementations including full `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]` class examples, routing key patterns, aggregate command handlers (factory + action), service injection, metadata access, and testing patterns with EcotoneLite. Load when you need full class definitions or handler wiring examples.
