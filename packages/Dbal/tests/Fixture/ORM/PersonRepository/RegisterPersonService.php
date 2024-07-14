<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\PersonRepository;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonWasRenamed;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

/**
 * licence Apache-2.0
 */
final class RegisterPersonService
{
    #[CommandHandler]
    public function register(RegisterPerson $command, ORMPersonRepository $repository): void
    {
        $repository->save(Person::register($command));
    }

    #[CommandHandler(Person::RENAME_COMMAND)]
    public function rename(string $command, array $metadata, ORMPersonRepository $repository, EventBus $eventBus): void
    {
        $id = $metadata['aggregate.id'];
        $person = $repository->get($id);
        $person->changeName($command);
        $eventBus->publish(new PersonWasRenamed($id, $command));
        $repository->save($person);
    }

    #[QueryHandler('person.byById')]
    public function getById(int $id, ORMPersonRepository $repository): Person
    {
        return $repository->get($id);
    }
}
