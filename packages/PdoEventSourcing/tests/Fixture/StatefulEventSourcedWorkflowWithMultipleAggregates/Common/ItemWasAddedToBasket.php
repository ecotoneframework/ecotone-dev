<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

use Ecotone\Api\NamedEvent;

#[NamedEvent('ItemWasAddedToBasket')]
class ItemWasAddedToBasket
{
    public function __construct(public string $basketId, public string $itemId, public int $quantity)
    {
    }
}
