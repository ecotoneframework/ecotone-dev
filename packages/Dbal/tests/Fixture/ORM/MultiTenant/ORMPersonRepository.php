<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\MultiTenant;

use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

final class ORMPersonRepository
{
    public function save(Person $person, MultiTenantConnectionFactory $multiTenantConnectionFactory): void
    {
        $multiTenantConnectionFactory->getRegistry()->getManager()->persist($person);
    }

    public function get(int $personId, MultiTenantConnectionFactory $multiTenantConnectionFactory): Person
    {
        $person = $this->getORMRepository($multiTenantConnectionFactory)->find($personId);
        Assert::notNull($person, "Person with id {$personId} not found");

        return $person;
    }

    private function getORMRepository(MultiTenantConnectionFactory $multiTenantConnectionFactory): \Doctrine\Persistence\ObjectRepository
    {
        return $multiTenantConnectionFactory->getRegistry()->getRepository(Person::class);
    }
}
