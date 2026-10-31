# Distribution API Reference

## DistributedBus Interface

```php
use Ecotone\Api\Gateway\DistributedBus;
use Ecotone\Api\ExtensionObject\MediaType;

interface DistributedBus
{
    public function sendCommand(
        string $targetServiceName,
        string $routingKey,
        string $command,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): void;

    public function convertAndSendCommand(
        string $targetServiceName,
        string $routingKey,
        object|array $command,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): void;

    public function publishEvent(
        string $routingKey,
        string $event,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): void;

    public function convertAndPublishEvent(
        string $routingKey,
        object|array $event,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): void;

    public function sendMessage(
        string $targetServiceName,
        string $routingKey,
        string $payload,
        string $sourceMediaType = MediaType::TEXT_PLAIN,
        array $metadata = [],
        ?string $replyRoutingKey = null,
        ?string $idempotencyKey = null
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
| `sendMessage` | Specific service + routing key | Raw string |

### Idempotency key

Every method takes an optional `idempotencyKey`. The target's deduplication (`ecotone/dbal`, on by default for
asynchronous endpoints) keys on it in place of the message id, so a send repeated by a retried handler is handled once.
The key is scoped by the sending service, the routing key and the consuming endpoint: it means this sender's retry of
its own request, so the order id can key both `tickets.confirm` and `tickets.cancel`, and two services reusing one
business id on one routing key are two requests, each handled. It is honoured where the target receives the distributed message; the asynchronous handlers the target
fans it out to and the messages it sends onwards are deduplicated by their own message id. A blank key, or one longer than
255 characters, is refused when `DistributedBus` is called.

```php
$distributedBus->sendMessage('ticketing', 'tickets.confirm', $orderId, idempotencyKey: $orderId);
```

## MessagePublisher Interface

```php
use Ecotone\Api\Gateway\MessagePublisher;
use Ecotone\Api\ExtensionObject\MediaType;

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

    // Publishes without blocking on delivery confirmation -- requires High Throughput
    // Publishing with non-blocking confirmation enabled on the publisher.
    public function publishDeferred(
        mixed $data,
        string $sourceMediaType = MediaType::APPLICATION_X_PHP,
        array $metadata = []
    ): Future;
}
```

### Method Summary

| Method | Description |
|--------|-------------|
| `send(data, sourceMediaType)` | Send raw string data |
| `sendWithMetadata(data, sourceMediaType, metadata)` | Send raw string with metadata |
| `convertAndSend(data)` | Send object/array (auto-converted) |
| `convertAndSendWithMetadata(data, metadata)` | Send object/array with metadata |
| `publishDeferred(data, sourceMediaType, metadata)` | Send without blocking on delivery confirmation (requires High Throughput Publishing) |

## #[Distributed] Attribute

```php
use Ecotone\Api\Attribute\Distributed;

#[Distributed(distributionReference: DistributedBus::class)]
```

- `distributionReference` -- defaults to `DistributedBus::class`, allows custom distribution reference
- Applied per handler method (alongside `#[CommandHandler]`/`#[EventHandler]`), not at the class level

## DistributedServiceMap API

```php
use Ecotone\Api\ExtensionObject\DistributedServiceMap;

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
