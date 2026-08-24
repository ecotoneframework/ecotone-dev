<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Api\CommandBus;
use Ecotone\Api\ErrorChannel;

/**
 * licence Enterprise
 */
#[ErrorChannel(ErrorConfigurationContext::ERROR_CHANNEL)]
interface SynchronousErrorChannelCommandBus extends CommandBus
{
}
