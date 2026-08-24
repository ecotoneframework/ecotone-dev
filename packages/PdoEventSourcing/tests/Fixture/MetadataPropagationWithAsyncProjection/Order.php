<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
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
