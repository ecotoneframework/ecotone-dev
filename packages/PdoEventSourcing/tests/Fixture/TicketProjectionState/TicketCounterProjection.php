<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

use Ecotone\Api\EventHandler;
use Ecotone\Api\FromStream;
use Ecotone\Api\Projection;
use Ecotone\Api\ProjectionState;
use Ecotone\EventSourcing\EventStreamEmitter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Projection(self::NAME)]
#[FromStream(Ticket::class)]
/**
 * licence Apache-2.0
 */
class TicketCounterProjection
{
    public const NAME = 'ticketCounter';

    #[EventHandler(endpointId: 'ticketCounter.addTicket')]
    public function whenTicketWasRegistered(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter, #[ProjectionState] array $state = []): array
    {
        if (! isset($state['ticketCount'])) {
            $state['ticketCount'] = 0;
        }

        $state['ticketCount'] += 1;

        $eventStreamEmitter->emit([new TicketCounterChanged($state['ticketCount'])]);

        return $state;
    }

    #[EventHandler(endpointId: 'ticketCounter.closeTicket')]
    public function whenTicketWasClosed(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter, #[ProjectionState] CounterState $state = new CounterState()): CounterState
    {
        $state->closedTicketCount += 1;

        $eventStreamEmitter->emit([new ClosedTicketCounterChanged($state->closedTicketCount)]);

        return $state;
    }
}
