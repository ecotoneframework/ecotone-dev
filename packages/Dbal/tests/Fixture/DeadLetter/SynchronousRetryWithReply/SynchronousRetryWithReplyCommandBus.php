<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Apache-2.0
 */
#[ErrorChannel(RetryConfiguration::ERROR_CHANNEL, retryChannelName: ErrorConfigurationContext::ASYNC_REPLY_CHANNEL)]
interface SynchronousRetryWithReplyCommandBus extends CommandBus
{
}
