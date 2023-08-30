<?php

namespace Test\Ecotone\Dbal\Fixture\ORM\SynchronousEventHandler;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonWasRenamed;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

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
