<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate;

use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\Identifier;

#[EventSourcingAggregate]
class NoFactoryMethodAggregateExample
{
    #[Identifier]
    private string $id;

    #[CommandHandler]
    public function doSomething(iterable $events): void
    {
    }
}
