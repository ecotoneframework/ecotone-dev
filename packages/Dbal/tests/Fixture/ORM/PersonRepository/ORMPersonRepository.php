<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\PersonRepository;

use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Modelling\AggregateNotFoundException;
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
        $person = $this->getObjectRepository()->find($personId);

        if ($person === null) {
            throw new AggregateNotFoundException("Person with id {$personId} not found");
        }

        return $person;
    }

    private function getObjectRepository(): \Doctrine\Persistence\ObjectRepository
    {
        return $this->managerRegistry->getRepository(Person::class);
    }
}
