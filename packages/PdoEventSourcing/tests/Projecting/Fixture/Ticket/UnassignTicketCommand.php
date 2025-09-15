<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

class UnassignTicketCommand
{
    public function __construct(public string $ticketId)
    {
    }
}
