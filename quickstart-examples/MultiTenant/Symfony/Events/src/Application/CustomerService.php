<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Event\CustomerWasRegistered;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Api\Dbal\Attribute\MultiTenantObjectManager;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\EventBus;
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

    #[EventHandler]
    public function sendNotificationWhen(
        CustomerWasRegistered $event,
        #[Header('tenant')] $tenant,
        #[Reference] NotificationSender $notificationSender,
        #[MultiTenantObjectManager] ObjectManager $objectManager,
    )
    {
        $customer = $objectManager->getRepository(Customer::class)->find($event->customerId);

        $notificationSender->sendWelcomeNotification($customer, $tenant);
    }
}