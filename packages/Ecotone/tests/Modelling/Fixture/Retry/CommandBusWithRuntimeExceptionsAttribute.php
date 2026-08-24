<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\CommandBus;
use Ecotone\Api\InstantRetry;
use RuntimeException;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 3, exceptions: [RuntimeException::class])]
interface CommandBusWithRuntimeExceptionsAttribute extends CommandBus
{
}
