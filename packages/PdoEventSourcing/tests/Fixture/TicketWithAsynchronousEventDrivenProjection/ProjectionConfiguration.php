<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection;

use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

class ProjectionConfiguration
{
    #[ServiceContext]
    public function setMaximumLimitedTimeForProjections()
    {
        return PollingMetadata::create('asynchronous_projections')
            ->setExecutionAmountLimit(3)
            ->setExecutionTimeLimitInMilliseconds(300);
    }

    #[ServiceContext]
    public function enableAsynchronousProjection()
    {
        return SimpleMessageChannelBuilder::createQueueChannel('asynchronous_projections');
    }
}
