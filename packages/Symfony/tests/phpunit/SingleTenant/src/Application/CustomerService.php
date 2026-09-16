<?php

declare(strict_types=1);

namespace Symfony\App\SingleTenant\Application;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Messaging\Support\Assert;
use RuntimeException;
use Symfony\App\SingleTenant\Application\Event\CustomerWasRegistered;

/**
 * licence Apache-2.0
 */
final class CustomerService
{
    private int $asyncExceptionTimes = 0;

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
        CustomerWasRegistered                                     $event,
        #[Reference] NotificationSender                           $notificationSender,
        #[Reference('doctrine.orm.entity_manager')] EntityManager $objectManager,
        #[Header('shouldThrowAsyncExceptionTimes')] int           $shouldThrowExceptionTimes = 0,
    ) {
        $customer = $objectManager->getRepository(Customer::class)->find($event->customerId);
        Assert::notNull($customer, 'Customer not found');

        if ($this->asyncExceptionTimes < $shouldThrowExceptionTimes) {
            $this->asyncExceptionTimes++;
            throw new RuntimeException('Throwing an exception during async.');
        }

        $notificationSender->sendWelcomeNotification($customer, '', $objectManager->getConnection());
    }
}
