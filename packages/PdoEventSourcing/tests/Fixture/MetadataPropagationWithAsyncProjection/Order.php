<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class Order
{
    use WithAggregateVersioning;

    #[Identifier]
    private int $id;

    #[CommandHandler(routingKey: 'order.create')]
    public static function create(int $id): array
    {
        return [new OrderCreated($id), new ProductAddedToOrder($id)];
    }

    #[EventSourcingHandler]
    public function applyOrderCreated(OrderCreated $event): void
    {
        $this->id = $event->id;
    }
}
