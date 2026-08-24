<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\MultiTenantConnection;
use Ecotone\Api\Dbal\MultiTenantObjectManager;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Api\Reference;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;
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