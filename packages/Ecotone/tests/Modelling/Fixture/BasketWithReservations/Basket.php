<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\BasketWithReservations;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class Basket
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $basketId;

    #[CommandHandler]
    public function addItem(AddItemToBasket $command): array
    {
        return [new ItemWasAddedToBasket($this->basketId, $command->itemId)];
    }

    #[EventHandler(endpointId: 'basket.itemWasAddedToBasket')]
    #[Asynchronous(channelName: 'basket')]
    public function whenItemWasAddedToBasket(ItemWasAddedToBasket $event): array
    {
        return [new ItemReservationCreated($event->itemId)];
    }

    #[EventSourcingHandler]
    public function applyBasketCreated(BasketCreated $event): void
    {
        $this->basketId = $event->basketId;
    }
}
