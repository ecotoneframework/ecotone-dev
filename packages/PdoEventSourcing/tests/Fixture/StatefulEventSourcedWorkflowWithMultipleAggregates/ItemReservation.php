<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates;

use Ecotone\Modelling\Attribute\TargetIdentifier;

class ItemReservation
{
    public function __construct(#[TargetIdentifier] public string $itemId, public int $quantity)
    {
    }
}
