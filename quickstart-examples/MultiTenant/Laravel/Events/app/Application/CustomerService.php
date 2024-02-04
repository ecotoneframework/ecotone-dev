<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Event\CustomerWasRegistered;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\DB;

final readonly class CustomerService
{
    #[CommandHandler]
    public function handle(RegisterCustomer $command, EventBus $eventBus)
    {
        Customer::register($command)->save();

        $eventBus->publish(new CustomerWasRegistered($command->customerId));
    }

    #[EventHandler]
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