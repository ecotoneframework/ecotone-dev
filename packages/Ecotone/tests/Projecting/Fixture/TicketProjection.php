<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture;

use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Projection;

#[Projection(self::NAME)]
class TicketProjection
{
    public const NAME = 'ticket_projection';
    public const MAX_ASSIGNMENT_COUNT = 2;

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
    public function whenTicketAssigned(TicketAssigned $event, #[ProjectionState] int $assignmentCount = 0): int
    {
        if ($assignmentCount >= self::MAX_ASSIGNMENT_COUNT) {
            return $assignmentCount;
        }

        $this->projectedEvents[] = $event;

        return $assignmentCount + 1;
    }

    #[EventHandler]
    public function whenTicketUnassigned(TicketUnassigned $event, #[ProjectionState] int $assignmentCount = 0): int
    {
        if ($assignmentCount === 0) {
            return $assignmentCount;
        }

        $this->projectedEvents[] = $event;

        return $assignmentCount - 1;
    }
}