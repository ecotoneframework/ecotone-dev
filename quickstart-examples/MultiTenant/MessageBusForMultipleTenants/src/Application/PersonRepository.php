<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Ecotone\Messaging\Config\MultiTenantConnectionFactory\MultiTenantConnectionFactory;
use Ecotone\Modelling\Attribute\QueryHandler;

final readonly class PersonRepository
{
    public function __construct(private MultiTenantConnectionFactory $connectionFactory)
    {

    }

    public function save(Customer $person): void
    {
        $this->getRegistry()->getManager(Customer::class)->persist($person);
    }

    public function find(int $personId): ?Customer
    {
        $this->getRegistry()->getRepository(Customer::class)->find($personId);
    }

    #[QueryHandler('person.getAllRegistered')]
    public function getAllRegisteredPersonIds(): array
    {
        return $this->connectionFactory->getConnection()->executeQuery(<<<SQL
    SELECT person_id FROM persons;
SQL)->fetchFirstColumn();
    }

    public function getRegistry(): ManagerRegistry
    {
        return $this->connectionFactory->getRegistry();
    }
}