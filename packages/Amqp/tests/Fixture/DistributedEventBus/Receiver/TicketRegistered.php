<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver;

/**
 * licence Apache-2.0
 */
final class TicketRegistered
{
    public function __construct(public string $value)
    {

    }
}
