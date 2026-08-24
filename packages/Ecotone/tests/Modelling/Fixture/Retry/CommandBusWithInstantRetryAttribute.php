<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\CommandBus;
use Ecotone\Api\InstantRetry;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 3)]
interface CommandBusWithInstantRetryAttribute extends CommandBus
{
}
