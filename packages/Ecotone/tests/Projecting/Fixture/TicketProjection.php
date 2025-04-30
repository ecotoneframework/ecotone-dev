<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Projection;

#[Projection('ticket_projection', 'ticket_stream_source')]
class TicketProjection
{
    private array $projectedEvents = [];

    public function getProjectedEvents(): array
    {
        return $this->projectedEvents;
    }

    public function clear(): void
    {
        $this->projectedEvents = [];
    }

    #[EventHandler]
    public function whenTicketCreated(TicketCreated $ticketCreated): void
    {
        $this->projectedEvents[] = $ticketCreated;
    }
}