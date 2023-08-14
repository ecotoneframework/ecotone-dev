<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\PersonRepository;

use Ecotone\Modelling\Attribute\CommandHandler;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;
use Test\Ecotone\Dbal\Fixture\ORM\Person\RegisterPerson;

final class RegisterPersonService
{
    #[CommandHandler]
    public function register(RegisterPerson $command, ORMPersonRepository $repository): void
    {
        $repository->save(Person::register($command));
    }
}
