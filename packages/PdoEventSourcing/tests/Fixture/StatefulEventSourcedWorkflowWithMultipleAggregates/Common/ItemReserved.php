<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('ItemReserved')]
class ItemReserved
{
    public function __construct(public string $itemId, public int $quantity)
    {
    }
}
