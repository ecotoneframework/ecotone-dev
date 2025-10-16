<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('ItemInventoryCreated')]
class ItemInventoryCreated
{
    public function __construct(public string $itemId)
    {
    }
}
