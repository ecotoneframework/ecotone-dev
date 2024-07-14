<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DbalBusinessInterfaceCommandHandler;

use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonQueryApi;
use Test\Ecotone\Dbal\Fixture\DbalBusinessInterface\PersonService;

/**
 * licence Apache-2.0
 */
final class PersonCommandService
{
    #[CommandHandler]
    public function register(RegisterPerson $command, PersonService $personService): void
    {
        $personService->insert($command->personId, $command->name);
    }

    #[QueryHandler('person.count')]
    public function countPersons(#[Reference] PersonQueryApi $personQueryApi): int
    {
        return $personQueryApi->countPersons();
    }
}
