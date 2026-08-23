<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousCustomRetry;

use Ecotone\Dbal\Api\ExtensionObject\DbalDeadLetterBuilder;
use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Gateway\CommandBus;

/**
 * licence Enterprise
 */
#[ErrorChannel(DbalDeadLetterBuilder::STORE_CHANNEL)]
interface SynchronousErrorChannelWithCustomRetryCommandBus extends CommandBus
{
}
