<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketProjectionState;

use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;

/**
 * licence Apache-2.0
 */
interface CounterStateGateway
{
    #[ProjectionStateGateway(TicketCounterProjection::NAME)]
    public function fetchState(): CounterState;
}
