<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\InstantRetry\Aggregate;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages\CustomerRegistered;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages\RegisterCustomer;

#[EventSourcingAggregate]
final class Customer
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $id;

    #[CommandHandler]
    public static function register(RegisterCustomer $command): array
    {
        return [new CustomerRegistered($command->id)];
    }

    #[EventSourcingHandler]
    public function applyCustomerRegistered(CustomerRegistered $event): void
    {
        $this->id = $event->id;
    }
}
