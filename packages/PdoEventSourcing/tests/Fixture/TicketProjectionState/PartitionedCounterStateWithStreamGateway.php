<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

use Ecotone\EventSourcing\Attribute\FromAggregateStream;
use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

interface PartitionedCounterStateWithStreamGateway
{
    #[ProjectionStateGateway('ticket_counter_multi_stream')]
    #[FromAggregateStream(Ticket::class)]
    public function fetchStateForPartition(string $aggregateId): CounterState;
}
