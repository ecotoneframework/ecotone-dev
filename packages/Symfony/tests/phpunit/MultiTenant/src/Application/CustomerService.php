<?php

declare(strict_types=1);

namespace Symfony\App\MultiTenant\Application;

use Doctrine\ORM\EntityManager;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\EventHandler;
use Symfony\App\MultiTenant\Application\Command\RegisterCustomer;
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
use Symfony\App\MultiTenant\Application\Event\CustomerWasRegistered;

final class CustomerService
{
    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(
        #[MultiTenantConnection] Connection $connection
    ): array
    {
        return $connection->executeQuery(<<<SQL
            SELECT customer_id FROM persons;    
SQL)->fetchFirstColumn();
    }

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'notificationSender')]
    public function sendNotificationWhen(
        CustomerWasRegistered $event,
        #[Header('tenant')] $tenant,
        #[Reference] NotificationSender $notificationSender,
        #[MultiTenantObjectManager] EntityManager $objectManager,
    )
    {
        $customer = $objectManager->getRepository(Customer::class)->find($event->customerId);

        $notificationSender->sendWelcomeNotification($customer, $tenant, $objectManager->getConnection());
    }
}