<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousRetryWithReply;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\Attribute\InstantRetry;
use Ecotone\Modelling\CommandBus;
use Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample\ErrorConfigurationContext;

/**
 * licence Apache-2.0
 */
#[InstantRetry(retryTimes: 1)]
#[ErrorChannel(ErrorConfigurationContext::ERROR_CHANNEL)]
interface SynchronousInstantRetryWithAsyncChannelCommandBus extends CommandBus
{
}
