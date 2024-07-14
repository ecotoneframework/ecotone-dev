<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketEmittingProjection;

/**
 * licence Apache-2.0
 */
final class TicketListUpdated
{
    public function __construct(public string $ticketId)
    {
    }
}
