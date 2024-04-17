<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Dbal\Attribute\MultiTenantConnection;
use Ecotone\Dbal\Attribute\MultiTenantObjectManager;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Illuminate\Support\Facades\DB;

final readonly class CustomerService
{
    #[CommandHandler]
    public function handle(
        RegisterCustomer $command,
        #[MultiTenantObjectManager] ObjectManager $objectManager
    ): void
    {
        $objectManager->persist(Customer::register($command));
    }

    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(
        #[MultiTenantConnection] Connection $connection
    ): array
    {
        return $connection->executeQuery(<<<SQL
            SELECT customer_id FROM persons;    
SQL)->fetchFirstColumn();
    }
}