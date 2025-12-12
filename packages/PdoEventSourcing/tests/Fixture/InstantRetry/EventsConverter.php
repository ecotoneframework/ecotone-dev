<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\InstantRetry;

use Ecotone\Messaging\Attribute\Converter;
use Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages\CustomerRegistered;

final class EventsConverter
{
    #[Converter]
    public function fromCustomerRegistered(CustomerRegistered $event): array
    {
        return ['id' => $event->id];
    }

    #[Converter]
    public function toCustomerRegistered(array $payload): CustomerRegistered
    {
        return new CustomerRegistered($payload['id']);
    }
}
