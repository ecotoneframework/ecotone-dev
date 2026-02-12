---
name: ecotone-identifier-mapping
description: >-
  Implements identifier mapping for Ecotone aggregates and sagas: native ID
  resolution from message properties, aggregate.id metadata override,
  #[TargetIdentifier] on commands/events, identifierMapping expressions on
  handler attributes, and #[IdentifierMethod] for method-based identifiers.
  Use when wiring commands/events to aggregates or sagas by identifier.
---

# Ecotone Identifier Mapping

## 1. Overview

When a command or event targets an existing aggregate or saga, Ecotone must resolve which instance to load. The identifier is resolved in this priority order:

1. **`aggregate.id` metadata** — override via message headers (highest priority)
2. **Native mapping** — command/event property name matches `#[Identifier]` property name
3. **`#[TargetIdentifier]`** — explicit mapping on command/event class property
4. **`identifierMapping`** — expression-based mapping on handler attribute
5. **`identifierMetadataMapping`** — header-based mapping on handler attribute

## 2. Declaring Identifiers on Aggregates and Sagas

Use `#[Identifier]` on the identity property:

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;
}
```

Same for sagas:

```php
use Ecotone\Modelling\Attribute\Saga;
use Ecotone\Modelling\Attribute\Identifier;

#[Saga]
class OrderProcess
{
    #[Identifier]
    private string $orderId;
}
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

### Method-Based Identifier with `#[IdentifierMethod]`

When the identifier property name differs from what the aggregate/saga exposes:

```php
use Ecotone\Modelling\Attribute\IdentifierMethod;
use Ecotone\Modelling\Attribute\Saga;

#[Saga]
class OrderProcess
{
    private string $id;

    #[IdentifierMethod('orderId')]
    public function getOrderId(): string
    {
        return $this->id;
    }
}
```

The `'orderId'` parameter tells Ecotone this method provides the value for the `orderId` identifier.

## 3. Native ID Mapping (Default)

When the command/event property name matches the aggregate's `#[Identifier]` property name, mapping is automatic:

```php
class CancelOrder
{
    public function __construct(public readonly string $orderId) {}
}

#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        // $orderId resolved automatically from $command->orderId
    }
}
```

This works because both the command and aggregate have a property named `orderId`.

## 4. `aggregate.id` Metadata Override

Pass the identifier directly via message metadata using the `aggregate.id` header. This overrides all other mapping strategies and is useful when the command has no message class or the property names do not match.

### With Routing Key Commands (No Message Class)

```php
#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;

    #[CommandHandler('order.cancel')]
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    #[QueryHandler('order.getStatus')]
    public function getStatus(): string
    {
        return $this->cancelled ? 'cancelled' : 'active';
    }
}
```

Sending with `aggregate.id`:

```php
$commandBus->sendWithRouting('order.cancel', metadata: ['aggregate.id' => $orderId]);
$queryBus->sendWithRouting('order.getStatus', metadata: ['aggregate.id' => $orderId]);
```

### In Tests

```php
$ecotone
    ->sendCommand(new PlaceOrder('order-1'))
    ->sendCommandWithRoutingKey('order.cancel', metadata: ['aggregate.id' => 'order-1']);
```

### With Multiple Identifiers

Pass an array to `aggregate.id`:

```php
$commandBus->sendWithRouting(
    'shelf.stock',
    metadata: ['aggregate.id' => ['warehouseId' => 'w1', 'productId' => 'p1']]
);
```

## 5. `#[TargetIdentifier]` on Commands/Events

When the command/event property name differs from the aggregate/saga identifier, use `#[TargetIdentifier]` to create an explicit mapping:

```php
use Ecotone\Modelling\Attribute\TargetIdentifier;

class OrderStarted
{
    public function __construct(
        #[TargetIdentifier('orderId')] public string $id
    ) {}
}
```

The parameter `'orderId'` tells Ecotone that `$id` maps to the aggregate/saga's `orderId` identifier.

### Full Saga Example

```php
#[Saga]
class OrderProcess
{
    #[Identifier]
    private string $orderId;

    #[EventHandler]
    public static function createWhen(OrderStarted $event): self
    {
        return new self($event->id);
    }

    #[EventHandler]
    public function onExistingOrder(OrderStarted $event): void
    {
        // Called on existing saga — orderId resolved via #[TargetIdentifier]
    }
}
```

### Without Parameter (Same Name)

When the property name already matches, use `#[TargetIdentifier]` without a parameter for explicitness:

```php
class CancelOrder
{
    public function __construct(
        #[TargetIdentifier] public readonly string $orderId
    ) {}
}
```

## 6. `identifierMapping` on Handler Attributes

Use expressions to map identifiers from the payload or headers. Available on both `#[CommandHandler]` and `#[EventHandler]`.

### Mapping from Payload

```php
#[Saga]
class OrderProcess
{
    #[Identifier]
    private string $orderId;

    #[EventHandler(identifierMapping: ['orderId' => 'payload.id'])]
    public static function createWhen(OrderStarted $event): self
    {
        return new self($event->id, $event->status);
    }

    #[EventHandler(identifierMapping: ['orderId' => 'payload.id'])]
    public function onExisting(OrderStarted $event): void
    {
        $this->status = $event->status;
    }
}
```

`'payload.id'` resolves to `$event->id`.

### Mapping from Headers

```php
#[Saga]
class OrderProcess
{
    #[Identifier]
    private string $orderId;

    #[EventHandler(identifierMapping: ['orderId' => "headers['orderId']"])]
    public function updateWhen(OrderStarted $event): void
    {
        $this->status = $event->status;
    }
}
```

Usage:

```php
$eventBus->publish(new OrderStarted('', 'ongoing'), metadata: ['orderId' => '123']);
```

### On Command Handlers

```php
#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;

    #[CommandHandler(identifierMapping: ['orderId' => 'payload.id'])]
    public function cancel(CancelOrder $command): void
    {
        $this->cancelled = true;
    }
}
```

## 7. `identifierMetadataMapping` on Handler Attributes

Maps aggregate/saga identifiers to specific metadata header names. Simpler than `identifierMapping` when the value comes directly from a header.

```php
#[Saga]
class OrderFulfilment
{
    #[Identifier]
    private string $orderId;

    #[CommandHandler('order.start')]
    public static function createWith(string $orderId): self
    {
        return new self($orderId);
    }

    #[EventHandler(identifierMetadataMapping: ['orderId' => 'paymentId'])]
    public function finishOrder(PaymentWasDoneEvent $event): void
    {
        $this->status = 'done';
    }
}
```

The `orderId` saga identifier is resolved from the `paymentId` header in metadata:

```php
$eventBus->publish(new PaymentWasDoneEvent(), metadata: ['paymentId' => $orderId]);
```

### Restriction

You cannot define both `identifierMetadataMapping` and `identifierMapping` on the same handler — use one or the other.

## 8. Testing

Basic testing pattern for identifier mapping:

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

$ecotone->sendCommand(new PlaceOrder('order-1'));
$ecotone->sendCommand(new CancelOrder('order-1'));

$this->assertTrue($ecotone->getAggregate(Order::class, 'order-1')->isCancelled());
```

## Key Rules

- Command/event properties matching `#[Identifier]` names are resolved automatically (native mapping)
- `aggregate.id` metadata overrides all other mapping — use it for routing-key-based commands without message classes
- `#[TargetIdentifier('identifierName')]` maps a differently-named property to the aggregate/saga identifier
- `identifierMapping` supports expressions: `'payload.propertyName'` and `"headers['headerName']"`
- `identifierMetadataMapping` maps identifiers to header names directly (simpler than `identifierMapping` for headers)
- You cannot combine `identifierMetadataMapping` and `identifierMapping` on the same handler
- Use `#[IdentifierMethod('identifierName')]` when the identifier value comes from a method rather than a property
- Factory handlers (static) do not need identifier mapping for creation — only action handlers on existing instances do

## Additional resources

- [Identifier mapping patterns](references/identifier-mapping-patterns.md) — Complete code examples for every identifier resolution strategy: full aggregate and saga classes with native mapping, `aggregate.id` override, `#[TargetIdentifier]`, `identifierMapping` from payload/headers, `identifierMetadataMapping`, and complete EcotoneLite test methods for each strategy. Load when you need full class definitions or copy-paste test examples.
