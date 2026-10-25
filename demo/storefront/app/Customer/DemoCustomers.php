<?php

declare(strict_types=1);

namespace App\Customer;

use Ecotone\Api\Attribute\ConsoleCommand;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Console\ConsoleWriter;
use Ecotone\Api\Gateway\CommandBus;
use Ecotone\Api\Gateway\QueryBus;

final class DemoCustomers
{
    private const CUSTOMERS = [
        'ada@example.com' => 'Ada Lovelace',
        'grace@example.com' => 'Grace Hopper',
        'linus@bounce.example' => 'Linus Bounce',
    ];

    #[ConsoleCommand('demo:seed')]
    public function seed(ConsoleWriter $writer, #[Reference] CommandBus $commandBus, #[Reference] QueryBus $queryBus): void
    {
        $registeredEmails = array_column($queryBus->sendWithRouting('customers.list'), 'email');
        foreach (self::CUSTOMERS as $email => $name) {
            if (in_array($email, $registeredEmails, true)) {
                continue;
            }
            $commandBus->send(new RegisterCustomer(name: $name, email: $email));
            $writer->info(sprintf('Registered customer %s <%s>.', $name, $email));
        }
        $writer->success('Demo customers are registered.');
    }
}
