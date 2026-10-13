<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection;

use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;
use Ecotone\Api\ExtensionObject\SimpleMessageChannelBuilder;

/**
 * licence Apache-2.0
 */
class ProjectionConfiguration
{
    #[ServiceContext]
    public function setMaximumLimitedTimeForProjections()
    {
        return PollingMetadata::create('asynchronous_projections')
            ->withExecutionAmountLimit(3)
            ->withExecutionTimeLimitInMilliseconds(300);
    }

    #[ServiceContext]
    public function enableAsynchronousProjection()
    {
        return SimpleMessageChannelBuilder::createQueueChannel('asynchronous_projections');
    }
}
