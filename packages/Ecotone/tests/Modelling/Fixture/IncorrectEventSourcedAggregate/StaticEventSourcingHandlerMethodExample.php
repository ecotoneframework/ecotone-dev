<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate;

use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventSourcingAggregate;
use Ecotone\Api\EventSourcingHandler;
use Ecotone\Api\Identifier;
use stdClass;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
class StaticEventSourcingHandlerMethodExample
{
    #[Identifier]
    private string $id;

    #[CommandHandler]
    public function doSomething(): void
    {
    }

    #[EventSourcingHandler]
    public static function factory(stdClass $object)
    {
    }
}
