<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\InstantRetry\Nested;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Reference;
use Ecotone\Api\Gateway\CommandBus;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages\RegisterCustomer;

final class CreateCustomerCaller
{
    #[CommandHandler('customer.create.via.caller')]
    public function create(RegisterCustomer $command, #[Reference] CommandBus $commandBus): void
    {
        $commandBus->send(new RegisterCustomer($command->id));
    }
}
