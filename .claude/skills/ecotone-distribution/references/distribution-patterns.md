# Distribution Patterns Reference

## DistributedServiceMap Full API

```php
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;

DistributedServiceMap::initialize(referenceName: DistributedBus::class)
```

### withCommandMapping

Maps a target service to a channel for command routing:

```php
->withCommandMapping(
    targetServiceName: 'order-service',
    channelName: 'orders_channel'
)
```

When `DistributedBus::sendCommand('order-service', ...)` is called, the message is routed to `orders_channel`.

### withEventMapping

Creates event subscriptions with routing key patterns:

```php
->withEventMapping(
    channelName: 'events_channel',
    subscriptionKeys: ['order.*', 'payment.completed'],
    excludePublishingServices: [],      // optional: blacklist
    includePublishingServices: [],      // optional: whitelist
)
```

- `subscriptionKeys` — glob patterns matched against event routing keys
- `excludePublishingServices` — events from these services are NOT sent to this channel
- `includePublishingServices` — ONLY events from these services are sent (whitelist)
- Cannot use both `exclude` and `include` at the same time

### withAsynchronousChannel

Makes the distributed bus process messages asynchronously:

```php
->withAsynchronousChannel('distributed_channel')
```

Requires a corresponding channel to be registered via `#[ServiceContext]`.

## DistributedBus Interface

```php
use Ecotone\Modelling\DistributedBus;
use Ecotone\Messaging\Conversion\MediaType;

interface DistributedBus
{
    public function sendCommand(
        string $targetServiceName,
        string $routingKey,
        string $command,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = []
    ): void;

    public function convertAndSendCommand(
        string $targetServiceName,
        string $routingKey,
        object|array $command,
        array $metadata = []
    ): void;

    public function publishEvent(
        string $routingKey,
        string $event,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = []
    ): void;

    public function convertAndPublishEvent(
        string $routingKey,
        object|array $event,
        array $metadata = []
    ): void;

    public function sendMessage(
        string $targetServiceName,
        string $targetChannelName,
        string $payload,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = []
    ): void;
}
```

### Method Details

| Method | Target | Payload |
|--------|--------|---------|
| `sendCommand` | Specific service | Raw string |
| `convertAndSendCommand` | Specific service | Object/array (auto-converted) |
| `publishEvent` | All subscribers | Raw string |
| `convertAndPublishEvent` | All subscribers | Object/array (auto-converted) |
| `sendMessage` | Specific service + channel | Raw string |

## #[Distributed] Attribute

```php
use Ecotone\Modelling\Attribute\Distributed;

#[Distributed(distributionReference: DistributedBus::class)]
```

- `distributionReference` — defaults to `DistributedBus::class`, allows custom distribution reference
- Applied to classes, marks all handlers in the class as distributed

## MessagePublisher Interface

```php
use Ecotone\Messaging\MessagePublisher;
use Ecotone\Messaging\Conversion\MediaType;

interface MessagePublisher
{
    public function send(
        string $data,
        string $sourceMediaType = MediaType::TEXT_PLAIN
    ): void;

    public function sendWithMetadata(
        string $data,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = []
    ): void;

    public function convertAndSend(object|array $data): void;

    public function convertAndSendWithMetadata(
        object|array $data,
        array $metadata
    ): void;
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

## Testing Distributed Handlers

```php
public function test_distributed_command_handling(): void
{
    $handler = new class {
        public ?PlaceOrder $received = null;

        #[Distributed]
        #[CommandHandler('order.place')]
        public function handle(PlaceOrder $command): void
        {
            $this->received = $command;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class],
        containerOrAvailableServices: [$handler],
    );

    $ecotone->sendCommandWithRoutingKey('order.place', new PlaceOrder('order-1'));

    $this->assertNotNull($handler->received);
    $this->assertEquals('order-1', $handler->received->orderId);
}
```

### Testing with DistributedBus

```php
public function test_distributed_event_publishing(): void
{
    $listener = new class {
        public array $events = [];

        #[Distributed]
        #[EventHandler('order.*')]
        public function handle(OrderWasPlaced $event): void
        {
            $this->events[] = $event;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$listener::class],
        containerOrAvailableServices: [$listener],
    );

    $ecotone->publishEventWithRoutingKey('order.placed', new OrderWasPlaced('order-1'));

    $this->assertCount(1, $listener->events);
}
```
