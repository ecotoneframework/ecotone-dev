<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('ItemWasAddedToBasket')]
class ItemWasAddedToBasket
{
    public function __construct(public string $basketId, public string $itemId, public int $quantity)
    {
    }
}
