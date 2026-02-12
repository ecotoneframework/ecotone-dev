---
name: ecotone-identifier-mapping
description: >-
  Implements identifier mapping for Ecotone aggregates and sagas: native ID
  resolution, aggregate.id metadata, #[TargetIdentifier], identifierMapping
  expressions, and #[IdentifierMethod]. Use when wiring commands/events to
  aggregates or sagas by identifier, resolving aggregate IDs from messages,
  or mapping event properties to saga identifiers.
---

# Ecotone Identifier Mapping

## Overview

When a command or event targets an existing aggregate or saga, Ecotone must resolve which instance to load. The identifier is resolved in this priority order:

1. **`aggregate.id` metadata** — override via message headers (highest priority)
2. **Native mapping** — command/event property name matches `#[Identifier]` property name
3. **`#[TargetIdentifier]`** — explicit mapping on command/event class property
4. **`identifierMapping`** — expression-based mapping on handler attribute
5. **`identifierMetadataMapping`** — header-based mapping on handler attribute

## Declaring Identifiers

Use `#[Identifier]` on the identity property of an aggregate or saga:

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

## Native ID Mapping (Default)

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

## `aggregate.id` Metadata Override

Pass the identifier directly via message metadata. Overrides all other mapping strategies:

```php
$commandBus->sendWithRouting('order.cancel', metadata: ['aggregate.id' => $orderId]);
```

## `#[TargetIdentifier]` on Commands/Events

When the command/event property name differs from the aggregate/saga identifier:

```php
use Ecotone\Modelling\Attribute\TargetIdentifier;

class OrderStarted
{
    public function __construct(
        #[TargetIdentifier('orderId')] public string $id
    ) {}
}
```

## `identifierMapping` on Handler Attributes

Use expressions to map identifiers from the payload or headers:

```php
#[EventHandler(identifierMapping: ['orderId' => 'payload.id'])]
public function onExisting(OrderStarted $event): void
{
    $this->status = $event->status;
}
```

## `identifierMetadataMapping` on Handler Attributes

Maps aggregate/saga identifiers to specific metadata header names:

```php
#[EventHandler(identifierMetadataMapping: ['orderId' => 'paymentId'])]
public function finishOrder(PaymentWasDoneEvent $event): void
{
    $this->status = 'done';
}
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

- [API Reference](references/api-reference.md) — Attribute signatures and parameter details for `#[Identifier]`, `#[TargetIdentifier]`, `#[IdentifierMethod]`, `identifierMapping`, and `identifierMetadataMapping`. Load when you need exact constructor parameters, types, or expression syntax.
- [Usage Examples](references/usage-examples.md) — Complete class implementations for every identifier resolution strategy: aggregates and sagas with native mapping, `aggregate.id` override (including multiple identifiers), `#[TargetIdentifier]` full saga flow, `identifierMapping` from payload and headers, `identifierMetadataMapping`, and `#[IdentifierMethod]`. Load when you need full, copy-paste-ready class definitions.
- [Testing Patterns](references/testing-patterns.md) — EcotoneLite test methods for each identifier mapping strategy: native mapping, `aggregate.id` override, `#[TargetIdentifier]` with sagas, `identifierMapping` from payload, and `identifierMapping` from headers. Load when writing tests for identifier resolution.
