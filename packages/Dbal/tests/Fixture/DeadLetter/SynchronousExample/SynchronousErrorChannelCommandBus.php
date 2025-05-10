<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeadLetter\SynchronousExample;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;

/**
 * licence Apache-2.0
 */
#[ErrorChannel(ErrorConfigurationContext::ERROR_CHANNEL)]
interface SynchronousErrorChannelCommandBus extends CommandBus
{
}
