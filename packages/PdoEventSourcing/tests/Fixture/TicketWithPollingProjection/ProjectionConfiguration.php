<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

/**
 * licence Apache-2.0
 */
class ProjectionConfiguration
{
    #[ServiceContext]
    public function setMaximumLimitedTimeForProjections()
    {
        return PollingMetadata::create(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->withExecutionAmountLimit(3)
            ->withExecutionTimeLimitInMilliseconds(300);
    }
}
