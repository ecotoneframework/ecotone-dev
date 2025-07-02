<?php

namespace Test\Ecotone\EventSourcing\Fixture\StatefulEventSourcedWorkflowWithMultipleAggregates;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent('BasketCreated')]
class BasketCreated
{
    public function __construct(public string $basketId)
    {
    }
}
