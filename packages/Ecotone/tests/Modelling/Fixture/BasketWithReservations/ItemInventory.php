<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\BasketWithReservations;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class ItemInventory
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $itemId;

    #[EventHandler(endpointId: 'item.itemReservationCreated')]
    #[Asynchronous(channelName: 'itemInventory')]
    public function whenItemReservationCreated(ItemReservationCreated $event): array
    {
        return [new ItemReserved($event->itemId)];
    }

    #[EventSourcingHandler]
    public function applyItemInventoryCreated(ItemInventoryCreated $event): void
    {
        $this->itemId = $event->itemId;
    }
}
