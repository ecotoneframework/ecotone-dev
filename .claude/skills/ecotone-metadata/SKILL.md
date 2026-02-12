---
name: ecotone-metadata
description: >-
  Implements message metadata (headers) in Ecotone: passing metadata to handlers
  via #[Header] and #[Headers], enriching with #[AddHeader]/#[RemoveHeader],
  modifying via interceptors with changeHeaders, automatic propagation from
  commands to events, and testing metadata with EcotoneLite.
  Use when working with message headers, metadata passing, header enrichment,
  metadata propagation, or testing metadata flows.
---

# Ecotone Message Metadata

## 1. Overview

Every message in Ecotone carries metadata (headers) alongside its payload. Metadata includes framework headers (id, correlationId, timestamp) and custom userland headers (userId, tenant, token, etc.). Userland headers automatically propagate from commands to events.

## 2. Passing Metadata When Sending Messages

All bus interfaces accept a `$metadata` array:

```php
$commandBus->send(new PlaceOrder('1'), metadata: ['userId' => '123']);
$commandBus->sendWithRouting('order.place', ['orderId' => '1'], metadata: ['userId' => '123']);
$eventBus->publish(new OrderWasPlaced('1'), metadata: ['source' => 'api']);
$queryBus->send(new GetOrder('1'), metadata: ['tenant' => 'acme']);
```

## 3. Accessing Metadata in Handlers

### Single Header with `#[Header]`

```php
use Ecotone\Messaging\Attribute\Parameter\Header;

class AuditService
{
    #[EventHandler]
    public function audit(
        OrderWasPlaced $event,
        #[Header('userId')] string $userId,
        #[Header('tenant')] ?string $tenant = null  // nullable = optional
    ): void {
        // $userId is extracted from metadata
        // $tenant is null if not present (because nullable)
    }
}
```

- Non-nullable `#[Header]` throws exception if header is missing
- Nullable `#[Header]` returns null if header is missing

### All Headers with `#[Headers]`

```php
use Ecotone\Messaging\Attribute\Parameter\Headers;

class LoggingService
{
    #[CommandHandler('logCommand')]
    public function log(#[Headers] array $headers): void
    {
        $userId = $headers['userId'];
        $correlationId = $headers['correlationId'];
    }
}
```

### Convention-Based (No Attribute)

When a handler has two parameters — first is payload, second is `array` — the second is automatically resolved as all headers:

```php
class OrderService
{
    #[CommandHandler('placeOrder')]
    public function handle($command, array $headers, EventBus $eventBus): void
    {
        // $headers automatically contains all message metadata
        $userId = $headers['userId'];
    }
}
```

## 4. Enriching Metadata Declaratively

### `#[AddHeader]` — Add a Header

```php
use Ecotone\Messaging\Attribute\Endpoint\AddHeader;

// Static value
#[AddHeader('token', '123')]
#[CommandHandler('process')]
public function process(): void { }

// Expression-based — access payload and headers
#[AddHeader('token', expression: 'headers["token"]')]
#[CommandHandler('process')]
public function process(): void { }
```

### `#[RemoveHeader]` — Remove a Header

```php
use Ecotone\Messaging\Attribute\Endpoint\RemoveHeader;

#[RemoveHeader('sensitiveData')]
#[CommandHandler('process')]
public function process(): void { }
```

### Combined Example

```php
#[Delayed(1000)]
#[AddHeader('token', '123')]
#[TimeToLive(1001)]
#[Priority(1)]
#[RemoveHeader('user')]
#[Asynchronous('async')]
#[CommandHandler('addHeaders', endpointId: 'addHeadersEndpoint')]
public function process(): void { }
```

## 5. Modifying Metadata with Interceptors

Use `changeHeaders: true` on `#[Before]`, `#[After]`, or `#[Presend]` interceptors. The interceptor must return an array that gets merged into existing headers.

### `#[Before]` — Enrich Before Handler

```php
#[Before(changeHeaders: true, pointcut: CommandHandler::class)]
public function addProcessedAt(#[Headers] array $headers): array
{
    return array_merge($headers, ['processedAt' => time()]);
}
```

### `#[Before]` — Add Static Header

```php
#[Before(pointcut: '*', changeHeaders: true)]
public function addMetadata(): array
{
    return ['safeOrder' => true];
}
```

### `#[After]` — Enrich After Handler

```php
#[After(pointcut: Logger::class, changeHeaders: true)]
public function addTimestamp(array $events, array $metadata): array
{
    return array_merge($metadata, ['notificationTimestamp' => time()]);
}
```

### `#[Presend]` — Enrich Before Channel

```php
#[Presend(pointcut: 'OrderFulfilment::finishOrder', changeHeaders: true)]
public function enrich(PaymentWasDoneEvent $event): array
{
    return ['paymentId' => $event->paymentId];
}
```

### Custom Attribute-Based Enrichment

Create a custom attribute and use it as an interceptor pointcut. The `#[Before]` interceptor receives the attribute instance:

```php
#[Before(changeHeaders: true)]
public function addMetadata(AddMetadata $addMetadata): array
{
    return [$addMetadata->getName() => $addMetadata->getValue()];
}

// Usage on handler:
#[CommandHandler('basket.add')]
#[AddMetadata('isRegistration', 'true')]
public static function start(array $command, array $headers): self { }
```

### `#[Around]` — Access Headers via Message

Around interceptors cannot use `changeHeaders`, but can read headers via `Message`:

```php
#[Around(pointcut: CommandHandler::class)]
public function log(MethodInvocation $invocation, Message $message): mixed
{
    $headers = $message->getHeaders()->headers();
    return $invocation->proceed();
}
```

## 6. Automatic Metadata Propagation

Ecotone automatically propagates userland headers from commands to events. When a command handler publishes events, all custom headers from the command are available in event handlers.

### What Propagates

- All custom/userland headers (e.g., `userId`, `tenant`, `token`)
- `correlationId` is always preserved
- `parentId` is set to the command's `messageId` when a new event message is created

### What Does NOT Propagate

- Framework headers (`OVERRIDE_AGGREGATE_IDENTIFIER`, aggregate internal headers)
- Polling metadata (`CONSUMER_POLLING_METADATA`)

### Example Flow

```
Command (userId=123) → CommandHandler → publishes Event → EventHandler receives (userId=123)
```

```php
class OrderService
{
    #[CommandHandler('placeOrder')]
    public function handle($command, array $headers, EventBus $eventBus): void
    {
        // $headers contains ['userId' => '123']
        $eventBus->publish(new OrderWasPlaced());
        // Event automatically gets userId=123 via propagation
    }

    #[EventHandler]
    public function notify(OrderWasPlaced $event, array $headers): void
    {
        // $headers['userId'] === '123' — propagated automatically!
    }
}
```

### Event-Sourced Aggregates

Events from event-sourced aggregates receive additional metadata:
- `_aggregate_type` — aggregate class name
- `_aggregate_id` — aggregate identifier
- `_aggregate_version` — aggregate version

### Disabling Propagation

Use `#[PropagateHeaders(false)]` on gateway methods:

```php
use Ecotone\Messaging\Attribute\PropagateHeaders;

interface OrderGateway
{
    #[MessageGateway('placeOrder')]
    #[PropagateHeaders(false)]
    public function placeOrderWithoutPropagation(#[Headers] $headers): void;
}
```

### Saga `identifierMetadataMapping`

Map metadata headers to saga identifiers:

```php
#[EventHandler(identifierMetadataMapping: ['orderId' => 'paymentId'])]
public function finishOrder(PaymentWasDoneEvent $event): void
{
    // 'orderId' saga identifier resolved from 'paymentId' header
}
```

## 7. Framework Headers Reference

| Constant | Value | Description |
|----------|-------|-------------|
| `MessageHeaders::MESSAGE_ID` | `'id'` | Unique message identifier |
| `MessageHeaders::MESSAGE_CORRELATION_ID` | `'correlationId'` | Correlates related messages |
| `MessageHeaders::PARENT_MESSAGE_ID` | `'parentId'` | Points to parent message |
| `MessageHeaders::TIMESTAMP` | `'timestamp'` | Message creation time |
| `MessageHeaders::CONTENT_TYPE` | `'contentType'` | Media type |
| `MessageHeaders::REVISION` | `'revision'` | Event revision number |
| `MessageHeaders::DELIVERY_DELAY` | `'deliveryDelay'` | Delay in milliseconds |
| `MessageHeaders::TIME_TO_LIVE` | `'timeToLive'` | TTL in milliseconds |
| `MessageHeaders::PRIORITY` | `'priority'` | Message priority |
| `MessageHeaders::EVENT_AGGREGATE_TYPE` | `'_aggregate_type'` | Aggregate class |
| `MessageHeaders::EVENT_AGGREGATE_ID` | `'_aggregate_id'` | Aggregate identifier |
| `MessageHeaders::EVENT_AGGREGATE_VERSION` | `'_aggregate_version'` | Aggregate version |

## 8. Testing Metadata with EcotoneLite

### Sending Metadata in Tests

```php
$ecotone->sendCommand(new PlaceOrder('1'), metadata: ['userId' => '123']);
$ecotone->sendCommandWithRoutingKey('placeOrder', metadata: ['userId' => '123']);
$ecotone->publishEvent(new OrderWasPlaced(), metadata: ['source' => 'test']);
$ecotone->sendQuery(new GetOrder('1'), metadata: ['tenant' => 'acme']);
```

### Verifying Event Headers

```php
$eventHeaders = $ecotone->getRecordedEventHeaders();
$firstHeaders = $eventHeaders[0];

$firstHeaders->get('userId');           // get specific header
$firstHeaders->getMessageId();          // framework helper
$firstHeaders->getCorrelationId();      // framework helper
$firstHeaders->getParentId();           // framework helper
$firstHeaders->containsKey('userId');   // check existence
$firstHeaders->headers();               // all headers as array
```

### Verifying Command Headers

```php
$commandHeaders = $ecotone->getRecordedCommandHeaders();
$firstHeaders = $commandHeaders[0];
```

## Key Rules

- Use `#[Header('name')]` for single header access, `#[Headers]` for all headers
- Convention: second `array` parameter is auto-resolved as headers (no attribute needed)
- `changeHeaders: true` only on `#[Before]`, `#[After]`, `#[Presend]` — NOT `#[Around]`
- Interceptors with `changeHeaders: true` must return an array
- Userland headers propagate automatically from commands to events
- Framework headers do NOT propagate
- Use `getRecordedEventHeaders()` / `getRecordedCommandHeaders()` to verify metadata in tests

## Additional resources

- [Metadata patterns reference](references/metadata-patterns.md) — Complete code examples for all metadata patterns including: full interceptor class implementations (Before/After/Presend with `changeHeaders`), custom attribute class definitions, metadata propagation test suites, correlation/parent ID verification tests, interceptor header modification tests, and AddHeader/RemoveHeader tests with async channels. Load when you need full class definitions, complete test examples, or the custom `AddMetadata` attribute implementation.
