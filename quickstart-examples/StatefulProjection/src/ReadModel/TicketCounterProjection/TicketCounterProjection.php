<?php

namespace App\ReadModel\TicketCounterProjection;

use App\Domain\Event\TicketWasRegistered;
use App\Domain\Ticket;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionState;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Modelling\Attribute\EventHandler;

#[Projection(self::NAME, Ticket::class)]
final class TicketCounterProjection
{
    const NAME = "ticket_counter";

    #[EventHandler]
    public function when(TicketWasRegistered $event, #[ProjectionState] TicketCounterState $state, EventStreamEmitter $eventStreamEmitter): TicketCounterState
    {
        $state = $state->increase();

        $eventStreamEmitter->emit([new TicketCounterWasChanged($state->count)]);

        return $state;
    }
}