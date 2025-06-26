<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates;

use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;

#[EventSourcingAggregate(withInternalEventRecorder: true)]
class Basket
{
    use WithEvents;
    use WithAggregateVersioning;

    #[Identifier]
    private string $basketId;

    #[CommandHandler(outputChannelName: 'itemInventory.makeReservation')]
    public function addItemToBasket(#[Payload] AddItemToBasket $command): ItemReservation
    {
        $this->recordThat(new ItemWasAddedToBasket($this->basketId, $command->itemId, $command->quantity));

        return new ItemReservation($command->itemId, $command->quantity);
    }

    #[EventSourcingHandler]
    public function applyBasketCreated(BasketCreated $event): void
    {
        $this->basketId = $event->basketId;
    }
}
