<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Gateway\CommandBus;

/**
 * licence Enterprise
 */
#[ErrorChannel(RetryConfiguration::ERROR_CHANNEL)]
interface SynchronousRetryWithAsyncRetryCommandBus extends CommandBus
{
}
