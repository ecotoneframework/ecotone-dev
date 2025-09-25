<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

use Ecotone\Modelling\Attribute\NamedEvent;

#[NamedEvent(self::NAME)]
class TicketAssigned
{
    public const NAME = 'ticket.assigned';
    public function __construct(public readonly string $ticketId)
    {
    }
}
