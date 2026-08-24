<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\Identifier;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class NoFactoryMethodAggregateExample
{
    #[Identifier]
    private string $id;

    #[CommandHandler]
    public function doSomething(iterable $events): void
    {
    }
}
