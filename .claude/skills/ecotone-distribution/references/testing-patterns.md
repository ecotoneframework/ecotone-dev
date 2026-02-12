# Distribution Testing Patterns

## Testing Distributed Command Handling

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

## Testing Distributed Event Publishing

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
