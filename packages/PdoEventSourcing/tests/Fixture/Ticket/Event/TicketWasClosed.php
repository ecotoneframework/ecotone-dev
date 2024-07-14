<?php

namespace Test\Ecotone\EventSourcing\Fixture\Ticket\Event;

/**
 * licence Apache-2.0
 */
class TicketWasClosed
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
