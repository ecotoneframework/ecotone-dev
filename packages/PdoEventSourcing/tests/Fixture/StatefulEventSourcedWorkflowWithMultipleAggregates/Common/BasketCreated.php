<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates\Common;

use Ecotone\Api\NamedEvent;

#[NamedEvent('BasketCreated')]
class BasketCreated
{
    public function __construct(public string $basketId)
    {
    }
}
