<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

use Ecotone\Api\Projecting\ProjectionStateGateway;

interface CounterStateV2Gateway
{
    #[ProjectionStateGateway('ticket_counter')]
    public function fetchState(): CounterState;
}
