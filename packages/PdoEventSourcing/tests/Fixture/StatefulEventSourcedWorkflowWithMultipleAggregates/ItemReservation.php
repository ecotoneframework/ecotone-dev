<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates;

class ItemReservation
{
    public function __construct(public string $itemId, public int $quantity)
    {
    }
}
