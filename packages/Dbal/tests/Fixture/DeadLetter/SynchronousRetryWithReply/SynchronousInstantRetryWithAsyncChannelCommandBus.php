<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\Attribute\InstantRetry;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 1)]
#[ErrorChannel(ErrorConfigurationContext::ERROR_CHANNEL)]
interface SynchronousInstantRetryWithAsyncChannelCommandBus extends CommandBus
{
}
