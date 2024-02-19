<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Event\CustomerWasRegistered;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Ecotone\Dbal\Attribute\MultiTenantObjectManager;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Attribute\Parameter\Reference;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
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