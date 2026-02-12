# Identifier Mapping Testing Patterns

## Basic Test Setup

```php
$ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);
```

## Test: Native Mapping

```php
public function test_aggregate_with_native_mapping(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone->sendCommand(new PlaceOrder('order-1'));
    $ecotone->sendCommand(new CancelOrder('order-1'));

    $this->assertTrue(
        $ecotone->getAggregate(Order::class, 'order-1')->isCancelled()
    );
}
```

## Test: `aggregate.id` Override

```php
public function test_aggregate_with_aggregate_id_metadata(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([Order::class]);

    $ecotone
        ->sendCommand(new PlaceOrder('order-1'))
        ->sendCommandWithRoutingKey('order.cancel', metadata: ['aggregate.id' => 'order-1']);

    $this->assertTrue(
        $ecotone->getAggregate(Order::class, 'order-1')->isCancelled()
    );
}
```

## Test: `#[TargetIdentifier]` with Saga

```php
public function test_saga_with_target_identifier(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting([OrderProcess::class]);

    $this->assertEquals(
        '123',
        $ecotone
            ->publishEvent(new OrderStarted('123'))
            ->getSaga(OrderProcess::class, '123')
            ->getOrderId()
    );
}
```

## Test: `identifierMapping` from Payload

```php
public function test_identifier_mapping_from_payload(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderProcessWithAttributePayloadMapping::class]
    );

    $this->assertEquals(
        'new',
        $ecotone
            ->publishEvent(new OrderStarted('123', 'new'))
            ->getSaga(OrderProcessWithAttributePayloadMapping::class, '123')
            ->getStatus()
    );
}
```

## Test: `identifierMapping` from Headers

```php
public function test_identifier_mapping_from_headers(): void
{
    $ecotone = EcotoneLite::bootstrapFlowTesting(
        [OrderProcessWithAttributeHeadersMapping::class]
    );

    $this->assertEquals(
        'ongoing',
        $ecotone
            ->sendCommandWithRoutingKey('startOrder', '123')
            ->publishEvent(
                new OrderStarted('', 'ongoing'),
                metadata: ['orderId' => '123']
            )
            ->getSaga(OrderProcessWithAttributeHeadersMapping::class, '123')
            ->getStatus()
    );
}
```
