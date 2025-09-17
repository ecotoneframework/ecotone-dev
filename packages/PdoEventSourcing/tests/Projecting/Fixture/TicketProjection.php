<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Projecting\Attribute\Projection;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketUnassigned;

#[Projection(self::NAME, MessageHeaders::EVENT_AGGREGATE_ID)]
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

    #[EventHandler(TicketAssigned::NAME)]
    public function whenTicketAssigned(array $event, #[ProjectionState] int $assignmentCount = 0): int
    {
        if ($assignmentCount >= self::MAX_ASSIGNMENT_COUNT) {
            return $assignmentCount;
        }

        $this->projectedEvents[] = new TicketAssigned($event['ticketId']);

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
