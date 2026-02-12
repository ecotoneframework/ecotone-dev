# Handler Testing Patterns

All handler tests use `EcotoneLite::bootstrapFlowTesting()` to bootstrap the framework with only the classes needed for the test.

## Testing a Command Handler

```php
use Ecotone\Lite\EcotoneLite;

public function test_command_handler(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderService::class],
        [new OrderService()],
    );

    $ecotone->sendCommand(new PlaceOrder('order-1', 'product-1'));

    $this->assertEquals(
        new OrderDTO('order-1', 'product-1', 'placed'),
        $ecotone->sendQuery(new GetOrder('order-1'))
    );
}
```

## Testing a Command Handler with Routing Key

```php
public function test_command_handler_with_routing_key(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderService::class],
        [new OrderService()],
    );

    $ecotone->sendCommandWithRoutingKey('order.place', ['orderId' => '123']);

    $this->assertEquals('123', $ecotone->sendQueryWithRouting('order.get', metadata: ['aggregate.id' => '123']));
}
```

## Testing an Event Handler

```php
public function test_event_handler_is_called(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [NotificationService::class],
        [$handler = new NotificationService()],
    );

    $ecotone->publishEvent(new OrderWasPlaced('order-1'));

    $this->assertTrue($handler->wasNotified());
}
```

## Testing Recorded Events

```php
public function test_recorded_events(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [Order::class],
    );

    $events = $ecotone
        ->sendCommand(new PlaceOrder('order-1', 'product-1'))
        ->getRecordedEvents();

    $this->assertEquals([new OrderWasPlaced('order-1')], $events);
}
```
