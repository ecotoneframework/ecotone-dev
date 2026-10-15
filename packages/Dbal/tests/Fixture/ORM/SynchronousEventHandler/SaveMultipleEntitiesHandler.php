<?php

namespace Test\Ecotone\Dbal\Fixture\ORM\SynchronousEventHandler;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Gateway\CommandBus;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonWasRenamed;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 */
class SaveMultipleEntitiesHandler
{
    #[EventHandler]
    public function whenPersonWasRenamed(PersonWasRenamed $event, CommandBus $commandBus): void
    {
        $commandBus->send(new RegisterPerson(
            $event->getPersonId() + 1,
            $event->getName() . '2'
        ));
    }
}
