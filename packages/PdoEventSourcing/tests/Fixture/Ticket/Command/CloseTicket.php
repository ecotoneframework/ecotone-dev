<?php

namespace Test\Ecotone\EventSourcing\Fixture\Ticket\Command;

/**
 * licence Apache-2.0
 */
class CloseTicket
{
    private string $ticketId;

    public function __construct(string $ticketId)
    {
        $this->ticketId = $ticketId;
    }

    public function getTicketId(): string
    {
        return $this->ticketId;
    }
}
