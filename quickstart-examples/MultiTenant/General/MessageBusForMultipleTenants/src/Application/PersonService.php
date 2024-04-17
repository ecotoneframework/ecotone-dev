<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

final class PersonService
{
    #[CommandHandler]
    public function handle(RegisterPerson $command, PersonRepository $personRepository): void
    {
        $personRepository->save(Person::register($command));
    }

    #[QueryHandler('person.getAllRegistered')]
    public function getAllRegisteredPersonIds(
        #[Reference] MultiTenantConnectionFactory $connectionFactory
    ): array
    {
        return $connectionFactory->getConnection()->executeQuery(<<<SQL
    SELECT person_id FROM persons;
SQL)->fetchFirstColumn();
    }
}