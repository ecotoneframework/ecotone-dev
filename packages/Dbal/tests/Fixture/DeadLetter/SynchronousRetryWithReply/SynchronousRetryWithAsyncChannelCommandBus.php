<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Api\CommandBus;
use Ecotone\Api\ErrorChannel;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Enterprise
 */
#[ErrorChannel(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL)]
interface SynchronousRetryWithAsyncChannelCommandBus extends CommandBus
{
}
