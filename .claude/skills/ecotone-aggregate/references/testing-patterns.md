# Aggregate Testing Patterns

All aggregate tests use `EcotoneLite::bootstrapFlowTesting()` to bootstrap the framework with only the aggregate classes needed for the test.

## State-Stored Aggregate Testing

```php
use Ecotone\Lite\EcotoneLite;

public function test_order_placement(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1', 'Widget'));

    $order = $ecotone->getAggregate(Order::class, 'order-1');
    $this->assertEquals('Widget', $order->getProduct());
}
```

## State-Stored with Multiple Commands

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Customer::class]);

$ecotone->sendCommand(new RegisterCustomer('c-1', 'John', 'john@example.com'));
$ecotone->sendCommand(new ChangeCustomerName('c-1', 'Jane'));

$customer = $ecotone->getAggregate(Customer::class, 'c-1');
// Assert state...
```

## Event-Sourced Aggregate Testing

Use `withEventsFor()` to set up pre-existing events before sending a command, and `getRecordedEvents()` to assert on newly produced events.

```php
public function test_ticket_close(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Ticket::class]);

    $events = $ecotone
        ->withEventsFor('ticket-1', Ticket::class, [
            new TicketWasRegistered('ticket-1', 'alert'),
        ])
        ->sendCommand(new CloseTicket('ticket-1'))
        ->getRecordedEvents();

    $this->assertEquals([new TicketWasClosed('ticket-1')], $events);
}
```

## Event-Sourced Testing with Pre-Set Events

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Product::class]);

$events = $ecotone
    ->withEventsFor('p-1', Product::class, [
        new ProductWasRegistered('p-1', 'Widget', 100),
    ])
    ->sendCommand(new ChangeProductPrice('p-1', 200))
    ->getRecordedEvents();

$this->assertEquals(
    [new ProductPriceWasChanged('p-1', 200, 100)],
    $events
);
```

## Event-Sourced with Event Store

Use `bootstrapFlowTestingWithEventStore()` when you need the full event store integration.

```php
public function test_with_event_store(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTestingWithEventStore(
        classesToResolve: [Ticket::class],
    );

    $ecotone->sendCommand(new RegisterTicket('ticket-1', 'Bug'));
    $events = $ecotone->getRecordedEvents();

    $this->assertEquals([new TicketWasRegistered('ticket-1', 'Bug')], $events);
}
```

## Testing with Multiple Identifiers

When an aggregate has composite identifiers, pass an array to `getAggregate()`.

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([ShelfItem::class]);

$ecotone->sendCommand(new AddShelfItem('warehouse-1', 'product-1', 50));

$item = $ecotone->getAggregate(ShelfItem::class, [
    'warehouseId' => 'warehouse-1',
    'productId' => 'product-1',
]);
```
