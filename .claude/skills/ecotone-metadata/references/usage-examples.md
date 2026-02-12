# Metadata Usage Examples

## Accessing Single Header in Handler

```php
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\EventHandler;

class NotificationService
{
    #[EventHandler]
    public function onOrderPlaced(
        OrderWasPlaced $event,
        #[Header('userId')] string $userId
    ): void {
        // Required header — throws if missing
    }

    #[EventHandler]
    public function onPaymentReceived(
        PaymentReceived $event,
        #[Header('region')] ?string $region = null
    ): void {
        // Optional header — null if missing
    }
}
```

## Accessing All Headers in Handler

```php
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;

class AuditService
{
    #[CommandHandler('audit')]
    public function handle(#[Headers] array $headers): void
    {
        $userId = $headers['userId'] ?? 'system';
        $correlationId = $headers['correlationId'];
    }
}
```

## Convention-Based Headers (No Attribute)

When the handler has two parameters (first = payload, second = array), the second is auto-resolved as headers:

```php
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\EventBus;

class OrderService
{
    #[CommandHandler('placeOrder')]
    public function handle($command, array $headers, EventBus $eventBus): void
    {
        $userId = $headers['userId'];
        $eventBus->publish(new OrderWasPlaced());
    }
}
```

## Sending Metadata via Bus

```php
// CommandBus
$commandBus->send(new PlaceOrder('1'), metadata: ['userId' => '123', 'tenant' => 'acme']);
$commandBus->sendWithRouting('order.place', ['orderId' => '1'], metadata: ['userId' => '123']);

// EventBus
$eventBus->publish(new OrderWasPlaced('1'), metadata: ['source' => 'api']);

// QueryBus
$queryBus->send(new GetOrder('1'), metadata: ['tenant' => 'acme']);
$queryBus->sendWithRouting('order.get', metadata: ['aggregate.id' => '123']);
```

## Declarative Header Enrichment

```php
use Ecotone\Messaging\Attribute\Endpoint\AddHeader;
use Ecotone\Messaging\Attribute\Endpoint\RemoveHeader;
use Ecotone\Messaging\Attribute\Endpoint\Delayed;
use Ecotone\Messaging\Attribute\Endpoint\Priority;
use Ecotone\Messaging\Attribute\Endpoint\TimeToLive;

// Static value
#[AddHeader('source', 'api')]
#[CommandHandler('process')]
public function process(): void { }

// Expression-based (access payload and headers)
#[AddHeader('token', expression: 'headers["token"]')]
#[CommandHandler('process')]
public function process(): void { }

// Remove a header
#[RemoveHeader('sensitiveData')]
#[CommandHandler('process')]
public function process(): void { }
```

## Combined Declarative Enrichment

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

## Before Interceptor with `changeHeaders`

```php
use Ecotone\Messaging\Attribute\Interceptor\Before;
use Ecotone\Messaging\Attribute\Parameter\Headers;
use Ecotone\Modelling\Attribute\CommandHandler;

class MetadataEnricher
{
    // Merge new headers into existing ones
    #[Before(changeHeaders: true, pointcut: CommandHandler::class)]
    public function addProcessedAt(#[Headers] array $metadata): array
    {
        return array_merge($metadata, ['processedAt' => time()]);
    }

    // Return only the new headers (they get merged automatically)
    #[Before(pointcut: '*', changeHeaders: true)]
    public function addSafeOrder(): array
    {
        return ['safeOrder' => true];
    }
}
```

## After Interceptor with `changeHeaders`

```php
use Ecotone\Messaging\Attribute\Interceptor\After;

class NotificationTimestampEnricher
{
    #[After(pointcut: Logger::class, changeHeaders: true)]
    public function addTimestamp(array $events, array $metadata): array
    {
        return array_merge($metadata, ['notificationTimestamp' => time()]);
    }
}
```

## Presend Interceptor with `changeHeaders`

```php
use Ecotone\Messaging\Attribute\Interceptor\Presend;

class PaymentEnricher
{
    #[Presend(pointcut: 'OrderFulfilment::finishOrder', changeHeaders: true)]
    public function enrich(PaymentWasDoneEvent $event): array
    {
        return ['paymentId' => $event->paymentId];
    }
}
```

## Custom Attribute-Based Header Enrichment

Define a custom attribute:

```php
use Attribute;

#[Attribute]
class AddMetadata
{
    public function __construct(
        private string $name,
        private string $value
    ) {}

    public function getName(): string { return $this->name; }
    public function getValue(): string { return $this->value; }
}
```

Use it as an interceptor pointcut. The `#[Before]` interceptor receives the attribute instance:

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

## Around Interceptor — Access Headers via Message

Around interceptors cannot use `changeHeaders`, but can read headers via `Message`:

```php
#[Around(pointcut: CommandHandler::class)]
public function log(MethodInvocation $invocation, Message $message): mixed
{
    $headers = $message->getHeaders()->headers();
    return $invocation->proceed();
}
```

## Metadata Propagation (Command to Event)

```php
class OrderService
{
    #[CommandHandler('placeOrder')]
    public function handle($command, array $headers, EventBus $eventBus): void
    {
        // $headers contains ['userId' => '123'] from the sender
        $eventBus->publish(new OrderWasPlaced());
        // No need to pass metadata — it's propagated automatically
    }

    #[EventHandler]
    public function notifyA(OrderWasPlaced $event, array $headers): void
    {
        // $headers['userId'] === '123' — propagated from command
    }

    #[EventHandler]
    public function notifyB(OrderWasPlaced $event, #[Header('userId')] string $userId): void
    {
        // $userId === '123' — propagated from command
    }
}
```

### What Gets Propagated

- All userland headers (userId, tenant, token, etc.)
- `correlationId` is always preserved from original message
- When event gets a new `messageId`, the command's `messageId` becomes `parentId`

### What Does NOT Get Propagated

- `OVERRIDE_AGGREGATE_IDENTIFIER` — aggregate internal routing
- `CONSUMER_POLLING_METADATA` — polling consumer metadata
- Other framework-internal headers

## Disabling Propagation

```php
use Ecotone\Messaging\Attribute\PropagateHeaders;

interface OrderGateway
{
    #[MessageGateway('placeOrder')]
    #[PropagateHeaders(false)]
    public function placeOrderWithoutPropagation(#[Headers] $headers): void;
}
```

## Event-Sourced Aggregate Metadata

Events from event-sourced aggregates automatically receive:

```php
$eventHeaders = $ecotone->getRecordedEventHeaders()[0];

// Userland headers propagated from command
$eventHeaders->get('userId');  // '123'

// Aggregate-specific framework headers
$eventHeaders->get(MessageHeaders::EVENT_AGGREGATE_TYPE);    // Order::class
$eventHeaders->get(MessageHeaders::EVENT_AGGREGATE_ID);      // 'order-123'
$eventHeaders->get(MessageHeaders::EVENT_AGGREGATE_VERSION); // 1
```

## Saga `identifierMetadataMapping`

Map metadata headers to saga identifiers:

```php
#[EventHandler(identifierMetadataMapping: ['orderId' => 'paymentId'])]
public function finishOrder(PaymentWasDoneEvent $event): void
{
    // 'orderId' saga identifier resolved from 'paymentId' header
}
```
