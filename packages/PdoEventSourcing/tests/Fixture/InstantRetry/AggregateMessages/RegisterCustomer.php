<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\InstantRetry\AggregateMessages;

final class RegisterCustomer
{
    public function __construct(public string $id)
    {
    }
}
