# Distribution Testing Patterns

The tests below exercise `#[Distributed]` handlers through ordinary routing, without a `DistributedServiceMap` --
that is enough to prove the handler itself, and needs no licence key (`#[Distributed]` is `licence Apache-2.0`).
A test that registers `DistributedServiceMap` to prove actual cross-service routing needs
`EcotoneLite::bootstrapFlowTesting(..., licenceKey: LicenceTesting::VALID_LICENCE)` -- see
[Enterprise Configuration Guide](../../ecotone-enterprise/references/configuration-guide.md).

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

    $ecotone->sendCommandWithRouting('order.place', new PlaceOrder('order-1'));

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

    $ecotone->publishEventWithRouting('order.placed', new OrderWasPlaced('order-1'));

    $this->assertCount(1, $listener->events);
}
```
