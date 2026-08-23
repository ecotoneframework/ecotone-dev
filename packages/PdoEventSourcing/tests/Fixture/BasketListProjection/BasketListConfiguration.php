<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketListProjection;

use Ecotone\EventSourcing\ProjectionRunningConfiguration;
use Ecotone\Api\Attribute\ServiceContext;
use Ecotone\Api\ExtensionObject\PollingMetadata;

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
