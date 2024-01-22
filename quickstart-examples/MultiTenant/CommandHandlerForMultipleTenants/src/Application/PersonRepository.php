<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;

final readonly class PersonRepository
{
    public function __construct(private MultiTenantConnectionFactory $connectionFactory)
    {

    }

    public function save(Person $person): void
    {
        /** @var EntityManager $entityManager */
        $entityManager = $this->getRegistry()->getRepository(Person::class);
        $entityManager->persist($person);
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