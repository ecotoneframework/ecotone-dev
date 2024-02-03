<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Modelling\Attribute\QueryHandler;

final readonly class PersonRepository
{
    public function __construct(private MultiTenantConnectionFactory $connectionFactory)
    {

    }

    public function save(Person $person): void
    {
        $this->getRegistry()->getManager(Person::class)->persist($person);
    }

    public function find(int $personId): ?Person
    {
        $this->getRegistry()->getRepository(Person::class)->find($personId);
    }

    public function getRegistry(): ManagerRegistry
    {
        return $this->connectionFactory->getRegistry();
    }
}