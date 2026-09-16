<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\AggregatesWithMetadataMapping;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Attribute\Payload;
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

    #[CommandHandler(routingKey: 'itemInventory.makeReservation', endpointId:  'itemInventory.makeReservation.endpoint', identifierMetadataMapping: ['itemId' => 'itemId'])]
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
