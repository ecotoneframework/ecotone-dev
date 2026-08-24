<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousCustomRetry;

use Ecotone\Api\CommandBus;
use Ecotone\Api\Dbal\DbalDeadLetterBuilder;
use Ecotone\Api\ErrorChannel;

/**
 * licence Enterprise
 */
#[ErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL)]
interface SynchronousErrorChannelWithCustomRetryCommandBus extends CommandBus
{
}
