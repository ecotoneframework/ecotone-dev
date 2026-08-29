<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

use Ecotone\Api\ProjectionStateGateway;

interface PartitionedCounterStateGateway
{
    #[ProjectionStateGateway('ticket_counter_partitioned')]
    public function fetchStateForPartition(string $aggregateId): CounterState;
}
