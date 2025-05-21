<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture\Ticket;

class TicketAssigned
{
    public const NAME = 'ticket.assigned';
    public function __construct(public readonly string $ticketId)
    {
    }
}