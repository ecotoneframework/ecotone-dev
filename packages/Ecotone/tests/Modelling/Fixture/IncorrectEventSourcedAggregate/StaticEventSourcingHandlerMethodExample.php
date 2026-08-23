<?php

namespace Test\Ecotone\Modelling\Fixture\IncorrectEventSourcedAggregate;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
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
