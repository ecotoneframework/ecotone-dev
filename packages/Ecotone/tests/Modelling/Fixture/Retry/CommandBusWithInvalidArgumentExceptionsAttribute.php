<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\Retry;

use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Gateway\CommandBus;
use InvalidArgumentException;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 3, exceptions: [InvalidArgumentException::class])]
interface CommandBusWithInvalidArgumentExceptionsAttribute extends CommandBus
{
}
