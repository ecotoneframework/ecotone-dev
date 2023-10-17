<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection;

final class TicketListUpdated
{
    public function __construct(public string $ticketId)
    {
    }
}
