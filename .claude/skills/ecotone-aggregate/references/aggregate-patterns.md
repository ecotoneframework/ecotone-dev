# Aggregate Patterns Reference

## Attribute Definitions

### Aggregate

Source: `Ecotone\Modelling\Attribute\Aggregate`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class Aggregate {}
```

### EventSourcingAggregate

Source: `Ecotone\Modelling\Attribute\EventSourcingAggregate`

```php
#[Attribute(Attribute::TARGET_CLASS)]
class EventSourcingAggregate {}
```

### Identifier

Source: `Ecotone\Modelling\Attribute\Identifier`

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Identifier
{
    public function __construct(public string $identifierPropertyName = '') {}
}
```

### EventSourcingHandler

Source: `Ecotone\Modelling\Attribute\EventSourcingHandler`

```php
#[Attribute(Attribute::TARGET_METHOD)]
class EventSourcingHandler {}
```

### AggregateVersion

Source: `Ecotone\Modelling\Attribute\AggregateVersion`

```php
#[Attribute(Attribute::TARGET_PROPERTY)]
class AggregateVersion {}
```

## State-Stored Aggregate Example

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Aggregate]
class Customer
{
    #[Identifier]
    private string $customerId;
    private string $name;
    private string $email;
    private bool $active = true;

    #[CommandHandler]
    public static function register(RegisterCustomer $command): self
    {
        $customer = new self();
        $customer->customerId = $command->customerId;
        $customer->name = $command->name;
        $customer->email = $command->email;
        return $customer;
    }

    #[CommandHandler]
    public function changeName(ChangeCustomerName $command): void
    {
        $this->name = $command->name;
    }

    #[CommandHandler]
    public function deactivate(DeactivateCustomer $command): void
    {
        $this->active = false;
    }

    #[QueryHandler]
    public function getDetails(GetCustomerDetails $query): array
    {
        return [
            'customerId' => $this->customerId,
            'name' => $this->name,
            'email' => $this->email,
            'active' => $this->active,
        ];
    }
}
```

## Event-Sourced Aggregate Example

```php
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
class Product
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $productId;
    private string $name;
    private int $price;
    private bool $published = false;

    #[CommandHandler]
    public static function register(RegisterProduct $command): array
    {
        return [new ProductWasRegistered(
            $command->productId,
            $command->name,
            $command->price,
        )];
    }

    #[CommandHandler]
    public function changePrice(ChangeProductPrice $command): array
    {
        if ($command->price === $this->price) {
            return [];
        }
        return [new ProductPriceWasChanged($this->productId, $command->price, $this->price)];
    }

    #[CommandHandler]
    public function publish(PublishProduct $command): array
    {
        if ($this->published) {
            return [];
        }
        return [new ProductWasPublished($this->productId)];
    }

    #[EventSourcingHandler]
    public function applyRegistered(ProductWasRegistered $event): void
    {
        $this->productId = $event->productId;
        $this->name = $event->name;
        $this->price = $event->price;
    }

    #[EventSourcingHandler]
    public function applyPriceChanged(ProductPriceWasChanged $event): void
    {
        $this->price = $event->newPrice;
    }

    #[EventSourcingHandler]
    public function applyPublished(ProductWasPublished $event): void
    {
        $this->published = true;
    }
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

    #[CommandHandler]
    public static function add(AddShelfItem $command): self
    {
        $item = new self();
        $item->warehouseId = $command->warehouseId;
        $item->productId = $command->productId;
        return $item;
    }
}

// Command with matching property names
class AddShelfItem
{
    public function __construct(
        public readonly string $warehouseId,
        public readonly string $productId,
        public readonly int $quantity,
    ) {}
}
```

## Aggregate with Event Publishing

State-stored aggregates that also publish events:

```php
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\WithEvents;

#[Aggregate]
class Order
{
    use WithEvents;

    #[Identifier]
    private string $orderId;

    #[CommandHandler]
    public static function place(PlaceOrder $command): self
    {
        $order = new self();
        $order->orderId = $command->orderId;
        $order->recordThat(new OrderWasPlaced($command->orderId));
        return $order;
    }
}
```

## Testing Patterns

### State-Stored Testing

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Customer::class]);

$ecotone->sendCommand(new RegisterCustomer('c-1', 'John', 'john@example.com'));
$ecotone->sendCommand(new ChangeCustomerName('c-1', 'Jane'));

$customer = $ecotone->getAggregate(Customer::class, 'c-1');
// Assert state...
```

### Event-Sourced Testing with Pre-Set Events

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Product::class]);

$events = $ecotone
    ->withEventsFor('p-1', Product::class, [
        new ProductWasRegistered('p-1', 'Widget', 100),
    ])
    ->sendCommand(new ChangeProductPrice('p-1', 200))
    ->getRecordedEvents();

$this->assertEquals(
    [new ProductPriceWasChanged('p-1', 200, 100)],
    $events
);
```

### Testing with Multiple Identifiers

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([ShelfItem::class]);

$ecotone->sendCommand(new AddShelfItem('warehouse-1', 'product-1', 50));

$item = $ecotone->getAggregate(ShelfItem::class, [
    'warehouseId' => 'warehouse-1',
    'productId' => 'product-1',
]);
```
