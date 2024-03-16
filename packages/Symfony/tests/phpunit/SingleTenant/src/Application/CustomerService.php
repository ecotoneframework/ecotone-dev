<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Ecotone\Dbal\Attribute\MultiTenantConnection;
use Ecotone\Dbal\Attribute\MultiTenantObjectManager;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Symfony\App\SingleTenant\Application\Event\CustomerWasRegistered;

final class CustomerService
{
    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(
        #[Reference] Connection $connection
    ): array {
        return $connection->executeQuery(<<<SQL
                        SELECT customer_id FROM persons;
            SQL)->fetchFirstColumn();
    }

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'notificationSender')]
    public function sendNotificationWhen(
        CustomerWasRegistered $event,
        #[Reference] NotificationSender $notificationSender,
        #[Reference('doctrine.orm.entity_manager')] EntityManager $objectManager,
    ) {
        $customer = $objectManager->getRepository(Customer::class)->find($event->customerId);

        $notificationSender->sendWelcomeNotification($customer, '', $objectManager->getConnection());
    }
}
