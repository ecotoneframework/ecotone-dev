<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\CommandBus;
use Ecotone\Api\ErrorChannel;
use Ecotone\Api\InstantRetry;

/**
 * licence Apache-2.0
 */
#[InstantRetry(retryTimes: 2)]
#[ErrorChannel('customErrorChannel')]
interface CommandBusWithErrorChannelAndInstantRetryAttribute extends CommandBus
{
}
