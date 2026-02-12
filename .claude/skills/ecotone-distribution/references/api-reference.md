# Distribution API Reference

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

### Method Summary

| Method | Target | Payload |
|--------|--------|---------|
| `sendCommand` | Specific service | Raw string |
| `convertAndSendCommand` | Specific service | Object/array (auto-converted) |
| `publishEvent` | All subscribers | Raw string |
| `convertAndPublishEvent` | All subscribers | Object/array (auto-converted) |
| `sendMessage` | Specific service + channel | Raw string |

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

### Method Summary

| Method | Description |
|--------|-------------|
| `send(data, sourceMediaType)` | Send raw string data |
| `sendWithMetadata(data, sourceMediaType, metadata)` | Send raw string with metadata |
| `convertAndSend(data)` | Send object/array (auto-converted) |
| `convertAndSendWithMetadata(data, metadata)` | Send object/array with metadata |

## #[Distributed] Attribute

```php
use Ecotone\Modelling\Attribute\Distributed;

#[Distributed(distributionReference: DistributedBus::class)]
```

- `distributionReference` -- defaults to `DistributedBus::class`, allows custom distribution reference
- Applied to classes, marks all handlers in the class as distributed

## DistributedServiceMap API

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

- `subscriptionKeys` -- glob patterns matched against event routing keys
- `excludePublishingServices` -- events from these services are NOT sent to this channel
- `includePublishingServices` -- ONLY events from these services are sent (whitelist)
- Cannot use both `exclude` and `include` at the same time

### withAsynchronousChannel

Makes the distributed bus process messages asynchronously:

```php
->withAsynchronousChannel('distributed_channel')
```

Requires a corresponding channel to be registered via `#[ServiceContext]`.
