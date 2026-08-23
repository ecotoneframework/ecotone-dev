<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Gateway\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Enterprise
 */
#[ErrorChannel(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL)]
interface SynchronousRetryWithAsyncChannelCommandBus extends CommandBus
{
}
