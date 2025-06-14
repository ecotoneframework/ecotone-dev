<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Enterprise
 */
#[ErrorChannel(ErrorConfigurationContext::ASYNC_REPLY_CHANNEL)]
interface SynchronousRetryWithAsyncChannelCommandBus extends CommandBus
{
}
