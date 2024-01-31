<?php

declare(strict_types=1);

namespace App\MultiTenant\Application;

use App\MultiTenant\Application\Command\RegisterCustomer;
use Ecotone\Modelling\Attribute\CommandHandler;

final class RegisterPersonHandler
{
    #[CommandHandler]
    public function handle(RegisterCustomer $command, CustomerService $personRepository)
    {
        $personRepository->save(Customer::register($command));
    }
}