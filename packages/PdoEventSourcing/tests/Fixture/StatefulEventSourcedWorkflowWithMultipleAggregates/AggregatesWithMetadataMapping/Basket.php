<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithMetadataMapping;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use Ecotone\Api\Payload;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\AddItemToBasket;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\BasketCreated;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemReservation;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemWasAddedToBasket;

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
