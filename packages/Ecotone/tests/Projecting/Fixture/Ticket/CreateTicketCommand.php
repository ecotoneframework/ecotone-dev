<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture\Ticket;

class CreateTicketCommand
{
    public function __construct(public string $ticketId)
    {
    }
}