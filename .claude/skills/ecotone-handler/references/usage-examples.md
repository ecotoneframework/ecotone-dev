# Handler Usage Examples

Complete, runnable code examples for Ecotone message handlers.

## Command Handler (Service)

```php
use Ecotone\Modelling\Attribute\CommandHandler;

class OrderService
{
    #[CommandHandler]
    public function placeOrder(PlaceOrder $command): void
    {
        // The command class type determines routing
        // PlaceOrder objects are automatically routed here
    }

    #[CommandHandler('order.cancel')]
    public function cancelOrder(array $payload): void
    {
        // String-based routing -- receives raw payload
        $orderId = $payload['orderId'];
    }
}
```

## Command Handler (Aggregate)

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;

#[Aggregate]
class Order
{
    #[Identifier]
    private string $orderId;
    private string $product;
    private bool $cancelled = false;

    // Static factory -- creates new aggregate instance
    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->orderId = $command->orderId;
        $order->product = $command->product;
        return $order;
    }

    // Instance method -- modifies existing aggregate
    #[CommandHandler]
    public function cancel(CancelOrder $command): void
    {
        $this->cancelled = true;
    }
}
```

## Event Handler (Sync and Async)

```php
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Messaging\Attribute\Asynchronous;

class NotificationService
{
    // Synchronous event handler
    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Send notification immediately
    }

    // Asynchronous event handler
    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'emailOnOrderPlaced')]
    public function sendEmail(OrderWasPlaced $event): void
    {
        // Processed via message channel
    }
}
```

## Query Handler (Class-Based and String-Based)

```php
use Ecotone\Modelling\Attribute\QueryHandler;

class ProductQueryService
{
    // Class-based routing
    #[QueryHandler]
    public function getProduct(GetProduct $query): ProductDTO
    {
        return $this->repository->find($query->productId);
    }

    // String-based routing
    #[QueryHandler('products.list')]
    public function listProducts(): array
    {
        return $this->repository->findAll();
    }
}
```

## Handler with Header Parameters

```php
use Ecotone\Messaging\Attribute\Parameter\Header;

class AuditService
{
    #[EventHandler]
    public function audit(
        OrderWasPlaced $event,
        #[Header('timestamp')] int $timestamp,
        #[Header('correlationId')] ?string $correlationId = null
    ): void {
        // Access message metadata via headers
    }
}
```

## ServiceActivator with Output Channel

```php
use Ecotone\Messaging\Attribute\ServiceActivator;

class TransformationService
{
    #[ServiceActivator(inputChannelName: 'transformChannel', outputChannelName: 'outputChannel')]
    public function transform(string $payload): string
    {
        return json_encode(['data' => $payload]);
    }
}
```

## Routing Key with CommandBus

```php
// Handler with routing key
#[CommandHandler('order.place')]
public function placeOrder(string $payload): void { }

// Sending via routing key
$commandBus->sendWithRouting('order.place', $payload);
$commandBus->sendWithRouting('order.place', $payload, MediaType::APPLICATION_JSON);
```
