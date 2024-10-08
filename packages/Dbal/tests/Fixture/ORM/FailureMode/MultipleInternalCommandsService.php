<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\FailureMode;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 */
final class MultipleInternalCommandsService
{
    #[Asynchronous('async')]
    #[CommandHandler('multipleInternalCommands', endpointId: 'multipleInternalCommandsEndpoint')]
    public function execute(array $commands, CommandBus $commandBus): void
    {
        foreach ($commands as $command) {
            $commandBus->send(new RegisterPerson(
                $command['personId'],
                $command['personName'],
                $command['exception'] ?? false
            ));
        }
    }

    #[Asynchronous('async')]
    #[CommandHandler('singeInternalCommand', endpointId: 'singleInternalCommandEndpoint', outputChannelName: 'person.register')]
    public function asyncRegister(array $command)
    {
        return new RegisterPerson(
            $command['personId'],
            $command['personName'],
            $command['exception'] ?? false
        );
    }
}
