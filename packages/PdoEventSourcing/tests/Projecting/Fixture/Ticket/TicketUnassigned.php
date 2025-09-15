<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

class TicketUnassigned
{
    public function __construct(public readonly string $ticketId)
    {
    }
}
