# Aggregate Usage Examples

Complete, runnable code examples for Ecotone aggregates.

## State-Stored Aggregate: Customer

A full state-stored aggregate with multiple command handlers and a query handler.

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

## Event-Sourced Aggregate: Product

A full event-sourced aggregate with multiple commands and event sourcing handlers.

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

## Multiple Identifiers: ShelfItem

An aggregate with a composite identifier.

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

## State-Stored Aggregate with Event Publishing

State-stored aggregates that also publish domain events using the `WithEvents` trait.

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
