<?php

namespace Test\Ecotone\EventSourcing\Fixture\BasketListProjection;

use Ecotone\Api\PollingMetadata;
use Ecotone\Api\ServiceContext;

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
}
