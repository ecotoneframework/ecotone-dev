<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service\Gateway;

use Ecotone\Messaging\Attribute\ErrorChannel;
use Ecotone\Modelling\CommandBus;

#[ErrorChannel('async')]
interface ErrorChannelWithAsyncChannel extends CommandBus
{
}
