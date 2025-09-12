<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

class AssignTicketCommand
{
    public function __construct(public string $ticketId)
    {
    }
}