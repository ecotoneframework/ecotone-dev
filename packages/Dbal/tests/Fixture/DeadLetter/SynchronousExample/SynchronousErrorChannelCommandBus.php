<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Api\Attribute\ErrorChannel;
use Ecotone\Api\Gateway\CommandBus;

/**
 * licence Enterprise
 */
#[ErrorChannel(ErrorConfigurationContext::ERROR_CHANNEL)]
interface SynchronousErrorChannelCommandBus extends CommandBus
{
}
