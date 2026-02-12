# Identifier Mapping Usage Examples

## Declaring Identifiers on Aggregates

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

## Declaring Identifiers on Sagas

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

## Multiple Identifiers (Composite Key)

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

## Method-Based Identifier with `#[IdentifierMethod]`

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

## Native ID Mapping (Full Aggregate Example)

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

## `aggregate.id` Metadata Override

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

### With Multiple Identifiers

Pass an array to `aggregate.id`:

```php
$commandBus->sendWithRouting(
    'shelf.stock',
    metadata: ['aggregate.id' => ['warehouseId' => 'w1', 'productId' => 'p1']]
);
```

## `#[TargetIdentifier]` Full Saga Example

```php
use Ecotone\Modelling\Attribute\TargetIdentifier;

class OrderStarted
{
    public function __construct(
        #[TargetIdentifier('orderId')] public string $id
    ) {}
}

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

## `identifierMapping` from Payload

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

## `identifierMapping` from Headers

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

## `identifierMapping` on Command Handlers

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

## `identifierMetadataMapping` Full Example

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
