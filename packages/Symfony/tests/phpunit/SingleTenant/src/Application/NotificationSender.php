<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Application;

use Doctrine\DBAL\Connection;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class NotificationSender
{
    public function sendWelcomeNotification(Customer $customer, string $tenant, Connection $connection): void
    {
        $connection->executeStatement('INSERT INTO customer_notifications (customer_id) VALUES (:customerId)', ['customerId' => $customer->getCustomerId()]);
        echo "Sending welcome notification to customer {$customer->getCustomerId()} for tenant {$tenant}\n";
    }

    #[QueryHandler('getNotificationsCount')]
    public function getNotifications(
        #[Reference] Connection $connection
    ): int {
        return (int)$connection->executeQuery('SELECT COUNT(customer_id) AS count FROM customer_notifications')->fetchOne();
    }
}
