<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousCustomRetry;

use Ecotone\Dbal\Recoverability\DbalDeadLetterBuilder;
use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;

/**
 * licence Enterprise
 */
#[ErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL)]
interface SynchronousErrorChannelWithCustomRetryCommandBus extends CommandBus
{
}
