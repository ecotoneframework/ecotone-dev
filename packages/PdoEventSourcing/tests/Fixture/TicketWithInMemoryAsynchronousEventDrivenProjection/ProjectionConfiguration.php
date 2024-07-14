<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Messaging\Attribute\ServiceContext;
use Ecotone\Messaging\Channel\SimpleMessageChannelBuilder;
use Ecotone\Messaging\Endpoint\PollingMetadata;

/**
 * licence Apache-2.0
 */
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

    #[ServiceContext]
    public function configureProjection()
    {
        return ProjectionRunningConfiguration::createEventDriven(InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION)
            ->withTestingSetup();
    }
}
