<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Event\CustomerWasRegistered;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\Header;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;
use Ecotone\Api\EventBus;
use Illuminate\Support\Facades\DB;

final readonly class CustomerService
{
    #[CommandHandler]
    public function handle(RegisterCustomer $command, EventBus $eventBus)
    {
        Customer::register($command)->save();

        $eventBus->publish(new CustomerWasRegistered($command->customerId));
    }

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: "notificationSender")]
    public function sendNotificationWhen(
        CustomerWasRegistered $event,
        NotificationSender $notificationSender,
        #[Header('tenant')] $tenant
    )
    {
        $customer = Customer::find($event->customerId);

        $notificationSender->sendWelcomeNotification($customer, $tenant);
    }
}