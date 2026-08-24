<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Event\CustomerWasRegistered;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\MultiTenantObjectManager;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\Header;
use Ecotone\Api\Reference;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\EventBus;
use Illuminate\Support\Facades\DB;

final readonly class CustomerService
{
    #[CommandHandler]
    public function handle(
        RegisterCustomer $command,
        #[MultiTenantObjectManager] ObjectManager $objectManager,
        #[Reference] EventBus $eventBus
    ): void
    {
        $objectManager->persist(Customer::register($command));

        $eventBus->publish(new CustomerWasRegistered($command->customerId));
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