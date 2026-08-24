<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketListProjection;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;
use Ecotone\EventSourcing\ProjectionRunningConfiguration;

/**
 * licence Apache-2.0
 */
class BasketListConfiguration
{
    #[ServiceContext]
    public function setMaximumOneRunForProjections()
    {
        return PollingMetadata::create(BasketList::PROJECTION_NAME)
            ->setExecutionAmountLimit(3)
            ->setExecutionTimeLimitInMilliseconds(300);
    }

    #[ServiceContext]
    public function enablePollingProjection()
    {
        return ProjectionRunningConfiguration::createPolling(BasketList::PROJECTION_NAME);
    }
}
