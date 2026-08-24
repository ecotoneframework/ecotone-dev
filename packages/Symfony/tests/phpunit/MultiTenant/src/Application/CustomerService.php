<?php

declare(strict_types=1);

namespace Symfony\App\MultiTenant\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\Dbal\MultiTenantConnection;
use Ecotone\Api\Dbal\MultiTenantObjectManager;
use Ecotone\Api\EventHandler;
use Ecotone\Api\Header;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\Reference;
use Symfony\App\MultiTenant\Application\Event\CustomerWasRegistered;

/**
 * licence Apache-2.0
 */
final class CustomerService
{
    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(
        #[MultiTenantConnection] Connection $connection
    ): array {
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
    ) {
        $customer = $objectManager->getRepository(Customer::class)->find($event->customerId);

        $notificationSender->sendWelcomeNotification($customer, $tenant, $objectManager->getConnection());
    }
}
