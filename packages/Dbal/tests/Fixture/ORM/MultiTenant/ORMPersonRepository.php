<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\MultiTenant;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

final class ORMPersonRepository
{
    public function __construct(private MultiTenantConnectionFactory $multiTenantConnectionFactory)
    {
    }

    public function save(Person $person): void
    {
        $this->getORMRepository()->persist($person);
    }

    public function get(int $personId): Person
    {
        $person = $this->getORMRepository()->find($personId);
        Assert::notNull($person, "Person with id {$personId} not found");

        return $person;
    }

    private function getORMRepository(): EntityManager
    {
        return $this->multiTenantConnectionFactory->getRegistry()->getRepository(Person::class);
    }
}
