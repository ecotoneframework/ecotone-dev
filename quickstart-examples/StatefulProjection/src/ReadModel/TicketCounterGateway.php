<?php

namespace App\ReadModel;

use App\ReadModel\TicketCounterProjection\TicketCounterProjection;
use App\ReadModel\TicketCounterProjection\TicketCounterState;
use Ecotone\EventSourcing\Attribute\ProjectionStateGateway;

interface TicketCounterGateway
{
    #[ProjectionStateGateway(TicketCounterProjection::NAME)]
    public function getCounter(): TicketCounterState;
}