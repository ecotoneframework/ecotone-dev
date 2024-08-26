<?php

declare(strict_types=1);

namespace Test\Ecotone\Amqp\Fixture\DistributedCommandBus\Receiver\Event;

final class TicketCreated
{
    public function __construct(private string $ticket)
    {
    }

    public function getTicket(): string
    {
        return $this->ticket;
    }
}