# Metadata Testing Patterns

## Sending Metadata in Tests

```php
$ecotone->sendCommand(new PlaceOrder('1'), metadata: ['userId' => '123']);
$ecotone->sendCommandWithRoutingKey('placeOrder', metadata: ['userId' => '123']);
$ecotone->publishEvent(new OrderWasPlaced(), metadata: ['source' => 'test']);
$ecotone->sendQuery(new GetOrder('1'), metadata: ['tenant' => 'acme']);
```

## Verifying Event Headers

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

## Verifying Command Headers

```php
$commandHeaders = $ecotone->getRecordedCommandHeaders();
$firstHeaders = $commandHeaders[0];
```

## Test: Metadata Propagation to Event Handlers

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

## Test: Correlation and Parent IDs

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

## Test: Before Interceptor Adds Headers

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

## Test: AddHeader and RemoveHeader

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

## Test: Async Metadata Propagation

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

## Test: Event-Sourced Aggregate Metadata

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

## Test: Propagation Disabled

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
