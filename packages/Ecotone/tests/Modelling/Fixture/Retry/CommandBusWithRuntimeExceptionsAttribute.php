<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Gateway\CommandBus;
use RuntimeException;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 3, exceptions: [RuntimeException::class])]
interface CommandBusWithRuntimeExceptionsAttribute extends CommandBus
{
}
