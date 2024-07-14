<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection;

/**
 * licence Apache-2.0
 */
final class TicketListUpdated
{
    public function __construct(public string $ticketId)
    {
    }
}
