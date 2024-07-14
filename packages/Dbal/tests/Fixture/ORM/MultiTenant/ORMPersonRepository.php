<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\MultiTenant;

use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Support\Assert;
use Test\Ecotone\Dbal\Fixture\ORM\Person\Person;

/**
 * licence Apache-2.0
 */
final class ORMPersonRepository
{
    public function save(Person $person, MultiTenantConnectionFactory $multiTenantConnectionFactory): void
    {
        $multiTenantConnectionFactory->getManager()->persist($person);
    }

    public function get(int $personId, MultiTenantConnectionFactory $multiTenantConnectionFactory): Person
    {
        $person = $this->getORMRepository($multiTenantConnectionFactory)->find($personId);
        Assert::notNull($person, "Person with id {$personId} not found");

        return $person;
    }

    private function getORMRepository(MultiTenantConnectionFactory $multiTenantConnectionFactory): \Doctrine\Persistence\ObjectRepository
    {
        return $multiTenantConnectionFactory->getManager()->getRepository(Person::class);
    }
}
