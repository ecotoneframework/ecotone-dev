<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Projecting\Partitioned;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\ProjectionState;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketAssigned;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketCreated;
use Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket\TicketUnassigned;

#[Projection(self::NAME), Partitioned]
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
