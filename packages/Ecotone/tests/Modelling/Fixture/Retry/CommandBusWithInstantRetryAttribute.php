<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Gateway\CommandBus;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 3)]
interface CommandBusWithInstantRetryAttribute extends CommandBus
{
}
