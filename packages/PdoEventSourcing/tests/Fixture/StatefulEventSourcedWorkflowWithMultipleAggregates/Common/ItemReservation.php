<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

class ItemReservation
{
    public function __construct(public string $itemId, public int $quantity)
    {
    }
}
