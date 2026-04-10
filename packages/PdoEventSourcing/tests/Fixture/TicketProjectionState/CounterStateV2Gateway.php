<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;

interface CounterStateV2Gateway
{
    #[ProjectionStateGateway('ticket_counter')]
    public function fetchState(): CounterState;
}
