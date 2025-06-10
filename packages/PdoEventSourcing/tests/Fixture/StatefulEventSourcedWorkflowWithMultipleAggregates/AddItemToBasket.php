<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates;

class AddItemToBasket
{
    public function __construct(public string $basketId, public string $itemId, public int $quantity)
    {
    }
}
