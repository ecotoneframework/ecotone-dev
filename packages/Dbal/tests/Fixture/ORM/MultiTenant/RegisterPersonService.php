<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\MultiTenant;

use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Enqueue\Dbal\DbalConnectionFactory;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonWasRenamed;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

final class RegisterPersonService
{
    #[CommandHandler]
    public function register(RegisterPerson $command, ORMPersonRepository $repository, #[Reference(DbalConnectionFactory::class)] MultiTenantConnectionFactory $multiTenantConnectionFactory): void
    {
        $repository->save(Person::register($command), $multiTenantConnectionFactory);
    }

    #[CommandHandler(Person::RENAME_COMMAND)]
    public function rename(string $command, array $metadata, ORMPersonRepository $repository, EventBus $eventBus, #[Reference(DbalConnectionFactory::class)] MultiTenantConnectionFactory $multiTenantConnectionFactory): void
    {
        $id = $metadata['aggregate.id'];
        $person = $repository->get($id, $multiTenantConnectionFactory);
        $person->changeName($command);
        $eventBus->publish(new PersonWasRenamed($id, $command));
        $repository->save($person, $multiTenantConnectionFactory);
    }

    #[QueryHandler('person.byById')]
    public function getById(int $id, ORMPersonRepository $repository, #[Reference(DbalConnectionFactory::class)] MultiTenantConnectionFactory $multiTenantConnectionFactory): Person
    {
        return $repository->get($id, $multiTenantConnectionFactory);
    }
}
