# Metadata Patterns Reference

## Attribute Definitions

### `#[Header]`

Source: `Ecotone\Messaging\Attribute\Parameter\Header`

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class Header
{
    public function __construct(string $headerName, string $expression = '')
}
```

### `#[Headers]`

Source: `Ecotone\Messaging\Attribute\Parameter\Headers`

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
class Headers
{
}
```

### `#[AddHeader]`

Source: `Ecotone\Messaging\Attribute\Endpoint\AddHeader`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AddHeader
{
    public function __construct(string $name, mixed $value = null, string|null $expression = null)
}
```

Either `$value` or `$expression` must be provided, not both.

### `#[RemoveHeader]`

Source: `Ecotone\Messaging\Attribute\Endpoint\RemoveHeader`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RemoveHeader
{
    public function __construct(string $name)
}
```

### `#[PropagateHeaders]`

Source: `Ecotone\Messaging\Attribute\PropagateHeaders`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class PropagateHeaders
{
    public function __construct(bool $propagate)
}
```

## Pattern: Accessing Single Header in Handler

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

## Pattern: Accessing All Headers in Handler

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

## Pattern: Convention-Based Headers (No Attribute)

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

## Pattern: Sending Metadata via Bus

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

## Pattern: Declarative Header Enrichment

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

## Pattern: Before Interceptor with changeHeaders

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

## Pattern: After Interceptor with changeHeaders

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

## Pattern: Presend Interceptor with changeHeaders

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

## Pattern: Custom Attribute-Based Header Enrichment

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

## Pattern: Metadata Propagation (Command → Event)

Ecotone propagates userland headers automatically:

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

## Pattern: Event-Sourced Aggregate Metadata

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

## Testing Patterns

### Test: Metadata Propagation to Event Handlers

```php
public function test_metadata_propagates_to_event_handlers(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [OrderService::class],
        containerOrAvailableServices: [new OrderService()]
    );

    $ecotone->sendCommandWithRoutingKey(
        'placeOrder',
        metadata: ['userId' => '123']
    );

    $notifications = $ecotone->sendQueryWithRouting('getAllNotificationHeaders');
    $this->assertCount(2, $notifications);
    $this->assertEquals('123', $notifications[0]['userId']);
    $this->assertEquals('123', $notifications[1]['userId']);
}
```

### Test: Correlation and Parent IDs

```php
public function test_correlation_id_propagates_to_events(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderService::class],
        [new OrderService()],
    );

    $messageId = Uuid::uuid4()->toString();
    $correlationId = Uuid::uuid4()->toString();

    $headers = $ecotone
        ->sendCommandWithRoutingKey(
            'placeOrder',
            metadata: [
                MessageHeaders::MESSAGE_ID => $messageId,
                MessageHeaders::MESSAGE_CORRELATION_ID => $correlationId,
            ]
        )
        ->getRecordedEventHeaders()[0];

    // Events get new message IDs
    $this->assertNotSame($messageId, $headers->getMessageId());
    // correlationId is preserved
    $this->assertSame($correlationId, $headers->getCorrelationId());
    // Command's messageId becomes event's parentId
    $this->assertSame($messageId, $headers->getParentId());
}
```

### Test: Before Interceptor Adds Headers

```php
public function test_before_interceptor_enriches_headers(): void
{
    $interceptor = new class {
        #[Before(changeHeaders: true, pointcut: CommandHandler::class)]
        public function enrich(): array
        {
            return ['enrichedBy' => 'interceptor'];
        }
    };

    $handler = new class {
        public array $receivedHeaders = [];

        #[CommandHandler('process')]
        public function handle(#[Headers] array $headers): void
        {
            $this->receivedHeaders = $headers;
        }
    };

    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [$handler::class, $interceptor::class],
        containerOrAvailableServices: [$handler, $interceptor],
    );

    $ecotone->sendCommandWithRoutingKey('process');

    $this->assertEquals('interceptor', $handler->receivedHeaders['enrichedBy']);
}
```

### Test: AddHeader and RemoveHeader

```php
public function test_add_and_remove_headers(): void
{
    $ecotoneLite = EcotoneLite::bootstrapFlowTesting(
        [AddingMultipleHeaders::class],
        [AddingMultipleHeaders::class => new AddingMultipleHeaders()],
        enableAsynchronousProcessing: [
            SimpleMessageChannelBuilder::createQueueChannel('async'),
        ],
        testConfiguration: TestConfiguration::createWithDefaults()
            ->withSpyOnChannel('async')
    );

    $headers = $ecotoneLite
        ->sendCommandWithRoutingKey('addHeaders', metadata: ['user' => '1233'])
        ->getRecordedEcotoneMessagesFrom('async')[0]
        ->getHeaders()->headers();

    // AddHeader added 'token'
    $this->assertEquals(123, $headers['token']);
    // RemoveHeader removed 'user'
    $this->assertArrayNotHasKey('user', $headers);
    // Delayed set delivery delay
    $this->assertEquals(1000, $headers[MessageHeaders::DELIVERY_DELAY]);
    // TimeToLive set TTL
    $this->assertEquals(1001, $headers[MessageHeaders::TIME_TO_LIVE]);
    // Priority set
    $this->assertEquals(1, $headers[MessageHeaders::PRIORITY]);
}
```

### Test: Async Metadata Propagation

```php
public function test_metadata_propagates_to_async_handlers(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [OrderService::class],
        containerOrAvailableServices: [new OrderService()],
        configuration: ServiceConfiguration::createWithAsynchronicityOnly()
            ->withExtensionObjects([
                SimpleMessageChannelBuilder::createQueueChannel('orders'),
            ])
    );

    $ecotone->sendCommandWithRoutingKey(
        'placeOrder',
        metadata: ['userId' => '123']
    );

    $ecotone->run('orders', ExecutionPollingMetadata::createWithTestingSetup(2));
    $notifications = $ecotone->sendQueryWithRouting('getAllNotificationHeaders');

    $this->assertCount(2, $notifications);
    $this->assertEquals('123', $notifications[0]['userId']);
    $this->assertEquals('123', $notifications[1]['userId']);
}
```

### Test: Event-Sourced Aggregate Metadata

```php
public function test_event_sourced_aggregate_metadata(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        classesToResolve: [Order::class],
    );

    $orderId = Uuid::uuid4()->toString();
    $ecotone->sendCommand(new PlaceOrder($orderId), metadata: ['userland' => '123']);

    $eventHeaders = $ecotone->getRecordedEventHeaders()[0];

    $this->assertSame('123', $eventHeaders->get('userland'));
    $this->assertSame($orderId, $eventHeaders->get(MessageHeaders::EVENT_AGGREGATE_ID));
    $this->assertSame(1, $eventHeaders->get(MessageHeaders::EVENT_AGGREGATE_VERSION));
    $this->assertSame(Order::class, $eventHeaders->get(MessageHeaders::EVENT_AGGREGATE_TYPE));
}
```

### Test: Propagation Disabled

```php
public function test_propagation_disabled_on_gateway(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderService::class, PropagatingGateway::class, PropagatingOrderService::class],
        [new OrderService(), new PropagatingOrderService()],
    );

    $ecotone->getGateway(PropagatingGateway::class)
        ->placeOrderWithoutPropagation(['token' => '123']);

    $headers = $ecotone->getRecordedEventHeaders()[0];
    $this->assertFalse($headers->containsKey('token'));
}
```
