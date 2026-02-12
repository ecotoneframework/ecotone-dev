---
name: ecotone-handler
description: >-
  Creates Ecotone message handlers with PHP attributes, proper
  endpointId configuration, and routing patterns. Covers CommandHandler,
  EventHandler, QueryHandler, and message metadata.
  Use when creating or modifying message handlers.
---

# Ecotone Message Handlers

## Overview

Message handlers are the core building blocks in Ecotone. They process messages using PHP 8.1+ attributes. Use this skill when creating command handlers (write operations), event handlers (side effects), query handlers (read operations), or service activators (low-level message endpoints).

## Handler Types

| Attribute | Purpose | Returns |
|-----------|---------|---------|
| `#[CommandHandler]` | Handles commands (write operations) | `void` or identifier |
| `#[EventHandler]` | Reacts to events (side effects) | `void` |
| `#[QueryHandler]` | Handles queries (read operations) | Data |
| `#[ServiceActivator]` | Low-level message endpoint | Varies |

## CommandHandler

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

## EventHandler

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

Multiple `#[EventHandler]` methods can listen to the same event -- all will be called.

## QueryHandler

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

## ServiceActivator

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

## Message Metadata with Headers

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

## Routing Patterns

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

## EndpointId Rules

- Every handler needs a unique `endpointId` when used with async processing or polling
- Naming convention: `'{context}.{action}'` e.g., `'order.place'`, `'notification.send'`
- The `endpointId` connects the handler to channel configuration and monitoring

```php
#[CommandHandler(endpointId: 'order.place')]
#[Asynchronous('orders')]
public function placeOrder(PlaceOrder $command): void { }
```

## Key Rules

- First parameter is the message object (type-hinted)
- `#[CommandHandler]` on aggregates: static = factory (creation), instance = action (modification)
- Use `#[Header]` for metadata access, not message wrapping
- PHPDoc `@param`/`@return` on public API methods
- No comments -- meaningful method names only

## Additional resources

- [API Reference](references/api-reference.md) -- Constructor signatures and parameter details for `#[CommandHandler]`, `#[EventHandler]`, `#[QueryHandler]`, `#[ServiceActivator]`, and `#[Header]` attributes. Load when you need exact parameter names, types, or defaults.
- [Usage Examples](references/usage-examples.md) -- Full class implementations: service command handlers with routing keys, aggregate command handlers (factory + action), async event handlers, query handlers with string routing, header parameter usage, and ServiceActivator wiring. Load when you need complete, copy-paste-ready handler implementations.
- [Testing Patterns](references/testing-patterns.md) -- EcotoneLite test setup for handlers, command/event/query testing, recorded events assertions, and routing key test patterns. Load when writing tests for handlers.
