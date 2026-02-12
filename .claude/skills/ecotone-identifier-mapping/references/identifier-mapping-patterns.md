# Identifier Mapping Patterns Reference

## Declaring Identifiers on Aggregates

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

## Multiple Identifiers

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

## Native ID Mapping (Full Example)

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

## `aggregate.id` Metadata Override (Full Examples)

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

## `#[TargetIdentifier]` Full Examples

### Basic Usage

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

### Full Saga Example with TargetIdentifier

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

## `identifierMapping` Full Examples

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

### Restriction

You cannot define both `identifierMetadataMapping` and `identifierMapping` on the same handler -- use one or the other.

## Testing Examples

### Native Mapping

```php
public function test_aggregate_with_native_mapping(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1'));
    $ecotone->sendCommand(new CancelOrder('order-1'));

    $this->assertTrue(
        $ecotone->getAggregate(Order::class, 'order-1')->isCancelled()
    );
}
```

### aggregate.id Override

```php
public function test_aggregate_with_aggregate_id_metadata(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone
        ->sendCommand(new PlaceOrder('order-1'))
        ->sendCommandWithRoutingKey('order.cancel', metadata: ['aggregate.id' => 'order-1']);

    $this->assertTrue(
        $ecotone->getAggregate(Order::class, 'order-1')->isCancelled()
    );
}
```

### #[TargetIdentifier] with Saga

```php
public function test_saga_with_target_identifier(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

    $this->assertEquals(
        '123',
        $ecotone
            ->publishEvent(new OrderStarted('123'))
            ->getSaga(OrderProcess::class, '123')
            ->getOrderId()
    );
}
```

### identifierMapping from Payload

```php
public function test_identifier_mapping_from_payload(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderProcessWithAttributePayloadMapping::class]
    );

    $this->assertEquals(
        'new',
        $ecotone
            ->publishEvent(new OrderStarted('123', 'new'))
            ->getSaga(OrderProcessWithAttributePayloadMapping::class, '123')
            ->getStatus()
    );
}
```

### identifierMapping from Headers

```php
public function test_identifier_mapping_from_headers(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderProcessWithAttributeHeadersMapping::class]
    );

    $this->assertEquals(
        'ongoing',
        $ecotone
            ->sendCommandWithRoutingKey('startOrder', '123')
            ->publishEvent(
                new OrderStarted('', 'ongoing'),
                metadata: ['orderId' => '123']
            )
            ->getSaga(OrderProcessWithAttributeHeadersMapping::class, '123')
            ->getStatus()
    );
}
```
