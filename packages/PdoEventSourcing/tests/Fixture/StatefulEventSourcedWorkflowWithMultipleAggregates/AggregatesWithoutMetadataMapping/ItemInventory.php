<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithoutMetadataMapping;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Payload;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Ecotone\Modelling\WithEvents;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\InventoryStockIncreased;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemInventoryCreated;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemReservation;
use Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common\ItemReserved;

#[EventSourcingAggregate(withInternalEventRecorder: true)]
class ItemInventory
{
    use WithEvents;
    use WithAggregateVersioning;

    #[Identifier]
    private string $itemId;

    private int $quantity = 0;

    #[CommandHandler(routingKey: 'itemInventory.makeReservation', endpointId:  'itemInventory.makeReservation.endpoint')]
    #[Asynchronous('itemInventory')]
    public function makeReservation(#[Payload] ItemReservation $itemReservation): void
    {
        $this->recordThat(new ItemReserved($this->itemId, $itemReservation->quantity));
    }

    #[EventSourcingHandler]
    public function applyItemInventoryCreated(ItemInventoryCreated $event): void
    {
        $this->itemId = $event->itemId;
    }

    #[EventSourcingHandler]
    public function applyInventoryStockIncreased(InventoryStockIncreased $event): void
    {
        $this->quantity += $event->quantity;
    }
}
