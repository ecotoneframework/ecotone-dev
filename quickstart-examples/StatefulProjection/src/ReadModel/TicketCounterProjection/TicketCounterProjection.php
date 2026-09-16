<?php

namespace App\ReadModel\TicketCounterProjection;

use App\Domain\Event\TicketWasRegistered;
use App\Domain\Ticket;
use Ecotone\Api\Projecting\Projection;
use Ecotone\Api\Projecting\FromAggregateStream;
use Ecotone\Api\Projecting\ProjectionState;
use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Api\Attribute\EventHandler;

#[Projection(self::NAME)]
#[FromAggregateStream(Ticket::class)]
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