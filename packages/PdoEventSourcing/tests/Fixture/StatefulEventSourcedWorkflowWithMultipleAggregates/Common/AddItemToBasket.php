<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

class AddItemToBasket
{
    public function __construct(public string $basketId, public string $itemId, public int $quantity)
    {
    }
}
