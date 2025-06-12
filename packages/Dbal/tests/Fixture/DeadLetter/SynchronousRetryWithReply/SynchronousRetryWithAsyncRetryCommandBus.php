<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply\RetryConfiguration;

/**
 * licence Apache-2.0
 */
#[ErrorChannel(RetryConfiguration::ERROR_CHANNEL)]
interface SynchronousRetryWithAsyncRetryCommandBus extends CommandBus
{
}
