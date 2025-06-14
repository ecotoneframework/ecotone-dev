<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;

/**
 * licence Enterprise
 */
#[ErrorChannel(RetryConfiguration::ERROR_CHANNEL)]
interface SynchronousRetryWithAsyncRetryCommandBus extends CommandBus
{
}
