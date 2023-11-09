<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\PersonRepository;

use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Messaging\Support\Assert;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

final class ORMPersonRepository
{
    public function __construct(private ManagerRegistry $managerRegistry)
    {
    }

    public function save(Person $person): void
    {
        $this->managerRegistry->getManager()->persist($person);
    }

    public function get(int $personId): Person
    {
        $person = $this->managerRegistry->getRepository(Person::class)->find($personId);
        Assert::notNull($person, "Person with id {$personId} not found");

        return $person;
    }
}
