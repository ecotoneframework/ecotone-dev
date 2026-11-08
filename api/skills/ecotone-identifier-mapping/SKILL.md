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

When a command or event targets an existing aggregate or saga, Ecotone must resolve which instance to load. When several sources are present, the one higher in this list wins:

1. **`aggregate.id` metadata** — override via message headers (highest priority)
2. **`identifierMapping`** / **`identifierMetadataMapping`** — expression- or header-based mapping on the handler attribute (one or the other, never both); overrides what is read from the payload
3. **`#[TargetIdentifier]`** — explicit mapping on a command/event class property
4. **Native mapping** — command/event property name matches the `#[Identifier]` property name

## Applies when

- A command or event has to reach an existing aggregate or saga instance
- The message property holding the id is named differently from the aggregate's or saga's identifier
- The event comes from another team or package and cannot be changed
- The id travels only in a message header, or the command is sent by routing key with no message class
- The aggregate exposes its identity through a method instead of an `#[Identifier]` property

## Declaring Identifiers

Use `#[Identifier]` on the identity property of an aggregate or saga:

```php
use Ecotone\Api\Attribute\Aggregate;
use Ecotone\Api\Attribute\Identifier;

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
use Ecotone\Api\Attribute\TargetIdentifier;

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

## Rules

### 1. Name the property like the identifier and add nothing else
When the message has a property named exactly like the `#[Identifier]` property (`orderId` ↔ `orderId`), Ecotone
reads the id from it; no attribute or mapping is needed.
- **Expected:** a message whose id property already matches the identifier name has no `#[TargetIdentifier]`,
  `identifierMapping` or `identifierMetadataMapping` for it.
- **Verified by:** `eval:order-and-shipping`

### 2. A differently named property in your own message gets `#[TargetIdentifier]`
`#[TargetIdentifier('orderId')] public string $id` maps that property to the `orderId` identifier; without an
argument it maps to an identifier of the property's own name. It takes precedence over name matching.
- **Expected:** a message class the application owns whose id property has another name marks that property with
  `#[TargetIdentifier('<identifier name>')]` from `Ecotone\Api\Attribute`.
- **Verified by:** `eval:order-and-shipping`

### 3. A message you cannot change is mapped on the handler with `identifierMapping`
`identifierMapping: ['orderId' => 'payload.reference']` evaluates an expression against `payload` and `headers`
(`"headers['x-order']"`) on the handler. Each key must be an identifier of the aggregate or saga, or bootstrap fails
("has no identifier ... for identifier mapping").
- **Expected:** the handler for a foreign message declares `identifierMapping` whose key is the identifier property
  name and whose value is a `payload.<property>` or `headers['<name>']` expression.
- **Verified by:** `eval:order-and-shipping`

### 4. An id carried in a header uses `identifierMetadataMapping`, never both mappings
`identifierMetadataMapping: ['orderId' => 'x-order-id']` takes the identifier from that header. A handler attribute
given both `identifierMetadataMapping` and `identifierMapping` throws ("You can't define both").
- **Expected:** a handler whose id arrives only in a header uses `identifierMetadataMapping` (or an `identifierMapping`
  `headers[...]` expression), and no handler declares both.
- **Verified by:** `eval:order-and-shipping`

### 5. A message without an id property passes `aggregate.id` metadata
For a routing-key command whose payload does not carry the id, send it as metadata:
`$commandBus->sendWithRouting('order.markAsPaid', $payload, metadata: ['aggregate.id' => $orderId])`. This header
overrides every other mapping.
- **Expected:** the sending code passes the id under the `aggregate.id` metadata key, and the handler needs no
  mapping for it.
- **Verified by:** `eval:order-and-shipping`

### 6. An identity exposed by a method uses `#[IdentifierMethod]`
When the aggregate keeps its identity in a property of another name, a public method marked
`#[IdentifierMethod('invoiceId')]` supplies the `invoiceId` identifier that messages are matched against.
- **Expected:** the aggregate has no `#[Identifier]` property for that id and exposes it through a public method
  carrying `#[IdentifierMethod('<identifier name>')]`; messages then carry a property of that identifier name.
- **Verified by:** `eval:order-and-shipping`

## Additional resources

- [API Reference](references/api-reference.md) — Attribute signatures and parameter details for `#[Identifier]`, `#[TargetIdentifier]`, `#[IdentifierMethod]`, `identifierMapping`, and `identifierMetadataMapping`. Load when you need exact constructor parameters, types, or expression syntax.
- [Usage Examples](references/usage-examples.md) — Complete class implementations for every identifier resolution strategy: aggregates and sagas with native mapping, `aggregate.id` override (including multiple identifiers), `#[TargetIdentifier]` full saga flow, `identifierMapping` from payload and headers, `identifierMetadataMapping`, and `#[IdentifierMethod]`. Load when you need full, copy-paste-ready class definitions.
- [Testing Patterns](references/testing-patterns.md) — EcotoneLite test methods for each identifier mapping strategy: native mapping, `aggregate.id` override, `#[TargetIdentifier]` with sagas, `identifierMapping` from payload, and `identifierMapping` from headers. Load when writing tests for identifier resolution.
