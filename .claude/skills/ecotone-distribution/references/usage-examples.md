# Distribution Usage Examples

## Producer Service (Sends Commands and Events)

```php
// Configuration
class ProducerConfig
{
    #[ServiceContext]
    public function serviceMap(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()
            ->withCommandMapping('order-service', 'orders_channel')
            ->withEventMapping(
                channelName: 'events_channel',
                subscriptionKeys: ['order.*'],
            )
            ->withAsynchronousChannel('distributed_channel');
    }

    #[ServiceContext]
    public function distributedChannel(): AmqpBackedMessageChannelBuilder
    {
        return AmqpBackedMessageChannelBuilder::create('distributed_channel');
    }
}

// Sender
class OrderCreator
{
    public function __construct(private DistributedBus $bus) {}

    public function createOrder(): void
    {
        $this->bus->convertAndSendCommand(
            'order-service',
            'order.place',
            new PlaceOrder('order-1', 'item-A'),
        );
    }
}
```

## Consumer Service (Receives Commands and Events)

```php
class OrderHandler
{
    #[Distributed]
    #[CommandHandler('order.place')]
    public function handleOrder(PlaceOrder $command): void
    {
        // Process the distributed command
    }

    #[Distributed]
    #[EventHandler('order.*')]
    public function onOrderEvent(OrderWasPlaced $event): void
    {
        // React to distributed events
    }
}
```

## Multi-Service Wiring Example

### Service A: Order Service (Producer + Consumer)

```php
// Configuration
class OrderServiceConfig
{
    #[ServiceContext]
    public function serviceMap(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()
            ->withCommandMapping('inventory-service', 'inventory_channel')
            ->withEventMapping(
                channelName: 'order_events',
                subscriptionKeys: ['inventory.*'],
            )
            ->withAsynchronousChannel('distributed');
    }

    #[ServiceContext]
    public function channels(): array
    {
        return [
            AmqpBackedMessageChannelBuilder::create('distributed'),
            AmqpBackedMessageChannelBuilder::create('inventory_channel'),
        ];
    }
}

// Send command to inventory service
class OrderWorkflow
{
    public function __construct(private DistributedBus $bus) {}

    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        $this->bus->convertAndSendCommand(
            'inventory-service',
            'inventory.reserve',
            new ReserveInventory($event->orderId, $event->items),
        );
    }
}

// Receive events from inventory service
class InventoryEventListener
{
    #[Distributed]
    #[EventHandler('inventory.reserved')]
    public function onInventoryReserved(InventoryReserved $event): void
    {
        // Handle inventory reservation confirmation
    }
}
```

### Service B: Inventory Service (Consumer + Publisher)

```php
class InventoryHandler
{
    #[Distributed]
    #[CommandHandler('inventory.reserve')]
    public function reserveStock(ReserveInventory $command): void
    {
        // Reserve inventory and publish event
    }
}
```

## MessagePublisher with Metadata

```php
use Ecotone\Messaging\MessagePublisher;

class NotificationSender
{
    public function __construct(private MessagePublisher $publisher) {}

    public function sendNotification(): void
    {
        // Send object (auto-converted)
        $this->publisher->convertAndSend(new OrderNotification('order-1'));

        // Send with metadata
        $this->publisher->convertAndSendWithMetadata(
            new OrderNotification('order-1'),
            ['priority' => 'high']
        );

        // Send raw string
        $this->publisher->send('{"orderId": "order-1"}', 'application/json');

        // Send raw string with metadata
        $this->publisher->sendWithMetadata(
            '{"orderId": "order-1"}',
            'application/json',
            ['correlation_id' => 'abc-123']
        );
    }
}
```

## Event Mapping with Service Filtering

```php
// Only receive events from specific services (whitelist)
->withEventMapping(
    channelName: 'events_channel',
    subscriptionKeys: ['order.*'],
    includePublishingServices: ['partner-service'],
)

// Exclude events from specific services (blacklist)
->withEventMapping(
    channelName: 'events_channel',
    subscriptionKeys: ['order.*'],
    excludePublishingServices: ['self'],
)
```
