<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
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
