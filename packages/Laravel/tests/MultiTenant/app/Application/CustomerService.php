<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use App\MultiTenant\Application\Event\CustomerWasRegistered;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use Illuminate\Support\Facades\DB;

final class CustomerService
{
    #[CommandHandler]
    public function handle(RegisterCustomer $command)
    {
        Customer::register($command)->save();
    }

    #[CommandHandler('customer.register_with_event')]
    public function handleWithEvent(
        RegisterCustomer $command,
        EventBus $eventBus,
        #[Header('shouldThrowException')] bool $shouldThrowException = false
    )
    {
        Customer::register($command)->save();

        $eventBus->publish(new CustomerWasRegistered($command->customerId));

        if ($shouldThrowException) {
            throw new \RuntimeException("Throwing an execption to test error handling.");
        }
    }

    #[CommandHandler('customer.register_with_business_interface')]
    public function handleWithDbaInterface(RegisterCustomer $command, CustomerInterface $customerInterface)
    {
        $customerInterface->register($command->customerId, $command->name);
    }

    #[QueryHandler('customer.getAllRegistered')]
    public function getAllRegisteredPersonIds(): array
    {
        return DB::connection()->getPdo()->query(<<<SQL
            SELECT customer_id FROM persons;    
SQL)->fetchAll(\PDO::FETCH_COLUMN);
    }

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'notificationSender')]
    public function sendNotificationWhen(
        CustomerWasRegistered $event,
        NotificationSender    $notificationSender,
        #[Header('tenant')]   $tenant
    )
    {
        $customer = Customer::find($event->customerId);

        $notificationSender->sendWelcomeNotification($customer, $tenant);
    }
}