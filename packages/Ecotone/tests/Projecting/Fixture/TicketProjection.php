<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Projection;

#[Projection(self::NAME, 'ticket_stream_source')]
class TicketProjection
{
    public const NAME = 'ticket_projection';
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
    public function whenTicketCreated(TicketCreated $event): void
    {
        $this->projectedEvents[] = $event;
    }

    #[EventHandler]
    public function whenTicketAssigned(TicketAssigned $event): void
    {
        $this->projectedEvents[] = $event;
    }
}