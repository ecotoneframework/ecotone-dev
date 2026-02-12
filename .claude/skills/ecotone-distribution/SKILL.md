---
name: ecotone-distribution
description: >-
  Implements distributed messaging between services in Ecotone:
  #[Distributed] attribute for event and command handlers,
  DistributedBus for cross-service communication,
  DistributedServiceMap for service routing configuration,
  and MessagePublisher for channel-based messaging.
  Use when setting up communication between applications,
  distributed event/command handlers, or message publishing with Service Map.
---

# Ecotone Distribution

## 1. #[Distributed] Attribute

Marks handlers as distributed — receivable from other services:

```php
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;

class OrderService
{
    #[Distributed]
    #[CommandHandler('order.place')]
    public function placeOrder(PlaceOrder $command): void
    {
        // Can be invoked from other services via DistributedBus
    }

    #[Distributed]
    #[EventHandler('order.placed')]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Receives events published from other services
    }
}
```

- Applied alongside `#[CommandHandler]` or `#[EventHandler]`
- Uses the handler's routing key for message matching
- Optional constructor parameter: `distributionReference` (defaults to `DistributedBus::class`)

## 2. DistributedBus

Interface for sending commands and events across services:

```php
use Ecotone\Modelling\DistributedBus;

class OrderSender
{
    public function __construct(private DistributedBus $distributedBus) {}

    public function placeOrderOnExternalService(): void
    {
        // Send command to a specific service
        $this->distributedBus->convertAndSendCommand(
            targetServiceName: 'order-service',
            routingKey: 'order.place',
            command: new PlaceOrder('order-1', 'item-A'),
        );
    }

    public function notifyAllServices(): void
    {
        // Publish event to all subscribing services
        $this->distributedBus->convertAndPublishEvent(
            routingKey: 'order.placed',
            event: new OrderWasPlaced('order-1'),
        );
    }
}
```

### DistributedBus Methods

| Method | Description |
|--------|-------------|
| `sendCommand(targetServiceName, routingKey, command, sourceMediaType, metadata)` | Send raw string command to a specific service |
| `convertAndSendCommand(targetServiceName, routingKey, command, metadata)` | Send object/array command (auto-converted) |
| `publishEvent(routingKey, event, sourceMediaType, metadata)` | Publish raw string event to all subscribers |
| `convertAndPublishEvent(routingKey, event, metadata)` | Publish object/array event (auto-converted) |
| `sendMessage(targetServiceName, targetChannelName, payload, sourceMediaType, metadata)` | Send raw message to a specific channel on a service |

## 3. DistributedServiceMap Configuration

Defines how commands are routed and which events are subscribed to:

```php
use Ecotone\Modelling\Api\Distribution\DistributedServiceMap;
use Ecotone\Messaging\Attribute\ServiceContext;

class DistributionConfig
{
    #[ServiceContext]
    public function serviceMap(): DistributedServiceMap
    {
        return DistributedServiceMap::initialize()
            ->withCommandMapping('order-service', 'orders_channel')
            ->withCommandMapping('payment-service', 'payments_channel')
            ->withEventMapping(
                channelName: 'events_channel',
                subscriptionKeys: ['order.*', 'payment.completed'],
            )
            ->withAsynchronousChannel('distributed_channel');
    }
}
```

### Command Mapping

Routes commands to the correct channel for a target service:

```php
->withCommandMapping(
    targetServiceName: 'order-service',  // Service name used in DistributedBus
    channelName: 'orders_channel'        // Message channel to send via
)
```

### Event Mapping

Subscribes to events matching routing key patterns:

```php
->withEventMapping(
    channelName: 'events_channel',            // Channel to send matching events to
    subscriptionKeys: ['order.*'],            // Routing key patterns (glob matching)
    excludePublishingServices: ['self'],      // Optional: blacklist services
    includePublishingServices: ['partner'],   // Optional: whitelist services (mutually exclusive with exclude)
)
```

### Async Channel

Makes the distributed bus send messages asynchronously:

```php
->withAsynchronousChannel('distributed_channel')
```

## 4. MessagePublisher

High-level interface for sending messages to channels:

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

### MessagePublisher Methods

| Method | Description |
|--------|-------------|
| `send(data, sourceMediaType)` | Send raw string data |
| `sendWithMetadata(data, sourceMediaType, metadata)` | Send raw string with metadata |
| `convertAndSend(data)` | Send object/array (auto-converted) |
| `convertAndSendWithMetadata(data, metadata)` | Send object/array with metadata |

## 5. Complete Example

### Producer Service (sends commands and events)

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

### Consumer Service (receives commands and events)

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

## Key Rules

- Use `#[Distributed]` on handlers that should be reachable from other services
- Use `DistributedBus` to send commands/events across service boundaries
- Configure routing with `DistributedServiceMap` via `#[ServiceContext]`
- Use `withCommandMapping()` for command routing and `withEventMapping()` for event subscriptions
- Use `withAsynchronousChannel()` to make distribution asynchronous
- `excludePublishingServices` and `includePublishingServices` are mutually exclusive in event mapping
- See `references/distribution-patterns.md` for detailed API reference
