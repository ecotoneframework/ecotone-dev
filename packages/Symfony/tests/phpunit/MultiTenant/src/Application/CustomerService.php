<?php

declare(strict_types=1);

namespace Symfony\App\MultiTenant\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Parameter\Header;
use Ecotone\Api\Attribute\Parameter\Reference;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Dbal\Api\Attribute\MultiTenantConnection;
use Ecotone\Dbal\Api\Attribute\MultiTenantObjectManager;
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
