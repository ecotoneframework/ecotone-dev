<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjectionMultiTenant;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

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
