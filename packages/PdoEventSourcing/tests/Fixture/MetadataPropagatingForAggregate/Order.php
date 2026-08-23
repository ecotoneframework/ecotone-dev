<?php

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagatingForAggregate;

use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Event;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class Order
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $orderId;

    #[CommandHandler('placeOrder')]
    public static function doSomething(string $orderId): array
    {
        return [new OrderWasPlaced($orderId)];
    }

    #[CommandHandler('placeOrderAndPropagateMetadata')]
    public static function doSomethingAndPropagateMetadata(string $orderId, array $headers): array
    {
        return [Event::create(new OrderWasPlaced($orderId), $headers)];
    }

    #[EventSourcingHandler]
    public function whenOrderWasPlaced(OrderWasPlaced $event): void
    {
        $this->orderId = $event->orderId;
    }
}
