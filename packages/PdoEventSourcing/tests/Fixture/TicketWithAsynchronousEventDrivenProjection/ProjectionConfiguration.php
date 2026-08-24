<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\Api\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
class ProjectionConfiguration
{
    #[ServiceContext]
    public function setMaximumLimitedTimeForProjections(): PollingMetadata
    {
        return PollingMetadata::create(InProgressTicketList::PROJECTION_CHANNEL)
            ->setExecutionAmountLimit(3)
            ->setExecutionTimeLimitInMilliseconds(300);
    }

    #[ServiceContext]
    public function enableAsynchronousProjection(): SimpleMessageChannelBuilder
    {
        return SimpleMessageChannelBuilder::createQueueChannel(InProgressTicketList::PROJECTION_CHANNEL);
    }
}
