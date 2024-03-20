<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver;

final class TicketRegistered
{
    public function __construct(public string $value)
    {

    }
}