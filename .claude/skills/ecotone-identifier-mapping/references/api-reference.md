# Identifier Mapping API Reference

## `#[Identifier]`

Source: `Ecotone\Modelling\Attribute\Identifier`

Marks a property as the identity of an aggregate or saga. Multiple `#[Identifier]` properties create a composite identifier.

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
class Identifier
{
}
```

Applied to properties on `#[Aggregate]` or `#[Saga]` classes:

```php
#[Identifier]
private string $orderId;
```

## `#[TargetIdentifier]`

Source: `Ecotone\Modelling\Attribute\TargetIdentifier`

Maps a command/event property to an aggregate/saga identifier when names differ.

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class TargetIdentifier
{
    public function __construct(string $identifierName = '')
}
```

**Parameters:**
- `identifierName` (string, default `''`) — The name of the `#[Identifier]` property on the aggregate/saga. When empty, the annotated property's own name is used (same-name matching).

## `#[IdentifierMethod]`

Source: `Ecotone\Modelling\Attribute\IdentifierMethod`

Declares a method that provides the value for a named identifier. Used when the identifier value must be computed or when the internal property name differs from the identifier name.

```php
#[Attribute(Attribute::TARGET_METHOD)]
class IdentifierMethod
{
    public function __construct(string $identifierName)
}
```

**Parameters:**
- `identifierName` (string, required) — The identifier name this method provides the value for. Must match the name used in commands/events (e.g., if commands use `orderId`, pass `'orderId'`).

## `identifierMapping` Parameter

Available on `#[CommandHandler]` and `#[EventHandler]` attributes.

```php
#[CommandHandler(identifierMapping: array $mapping)]
#[EventHandler(identifierMapping: array $mapping)]
```

**Type:** `array<string, string>` — Maps identifier names to expressions.

**Expression syntax:**
- `'payload.propertyName'` — Resolves to the message payload's property (e.g., `'payload.id'` resolves to `$event->id`)
- `"headers['headerName']"` — Resolves to a message header value (e.g., `"headers['orderId']"` resolves to the `orderId` metadata header)

**Example:**

```php
#[EventHandler(identifierMapping: ['orderId' => 'payload.id'])]
```

## `identifierMetadataMapping` Parameter

Available on `#[CommandHandler]` and `#[EventHandler]` attributes.

```php
#[CommandHandler(identifierMetadataMapping: array $mapping)]
#[EventHandler(identifierMetadataMapping: array $mapping)]
```

**Type:** `array<string, string>` — Maps identifier names to metadata header names directly.

**Example:**

```php
#[EventHandler(identifierMetadataMapping: ['orderId' => 'paymentId'])]
```

The `orderId` identifier is resolved from the `paymentId` metadata header.

**Restriction:** You cannot define both `identifierMetadataMapping` and `identifierMapping` on the same handler.

## `aggregate.id` Metadata Header

A special metadata key that overrides all other identifier resolution strategies.

**Single identifier:**

```php
$commandBus->sendWithRouting('order.cancel', metadata: ['aggregate.id' => $orderId]);
```

**Multiple identifiers (composite key):**

```php
$commandBus->sendWithRouting('shelf.stock', metadata: [
    'aggregate.id' => ['warehouseId' => 'w1', 'productId' => 'p1']
]);
```
