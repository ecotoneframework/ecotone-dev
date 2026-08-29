<?php

namespace App\ReadModel\TicketCounterProjection;

use App\Domain\Event\TicketWasRegistered;
use App\Domain\Ticket;
use Ecotone\Api\Projection;
use Ecotone\Api\FromStream;
use Ecotone\Api\ProjectionState;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Api\EventHandler;

#[Projection(self::NAME)]
#[FromStream(Ticket::class)]
final class TicketCounterProjection
{
    const NAME = "ticket_counter";

    #[EventHandler]
    public function when(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter, #[ProjectionState] TicketCounterState $state = new TicketCounterState(0)): TicketCounterState
    {
        $state = $state->increase();

        $eventStreamEmitter->emit([new TicketCounterWasChanged($state->count)]);

        return $state;
    }
}