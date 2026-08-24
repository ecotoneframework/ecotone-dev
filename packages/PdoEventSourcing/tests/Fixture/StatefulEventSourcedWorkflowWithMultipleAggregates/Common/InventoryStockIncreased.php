<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

use Ecotone\Api\NamedEvent;

#[NamedEvent('InventoryStockIncreased')]
class InventoryStockIncreased
{
    public function __construct(public string $itemId, public int $quantity)
    {
    }
}
