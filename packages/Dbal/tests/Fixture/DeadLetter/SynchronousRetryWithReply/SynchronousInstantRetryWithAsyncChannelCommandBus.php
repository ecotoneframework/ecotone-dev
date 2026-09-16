<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Attribute\InstantRetry;
use Ecotone\Api\Gateway\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Enterprise
 */
#[InstantRetry(retryTimes: 1)]
#[ErrorChannel(ErrorConfigurationContext::ERROR_CHANNEL)]
interface SynchronousInstantRetryWithAsyncChannelCommandBus extends CommandBus
{
}
