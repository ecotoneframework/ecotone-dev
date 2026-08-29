<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;

/**
 * licence Apache-2.0
 */
class ProjectionConfiguration
{
    #[ServiceContext]
    public function setMaximumLimitedTimeForProjections()
    {
        return PollingMetadata::create(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->setExecutionAmountLimit(3)
            ->setExecutionTimeLimitInMilliseconds(300);
    }
}
