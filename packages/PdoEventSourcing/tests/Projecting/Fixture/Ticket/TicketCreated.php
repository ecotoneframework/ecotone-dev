<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

class TicketCreated
{
    public function __construct(
        public readonly string $ticketId,
    ) {
    }
}
