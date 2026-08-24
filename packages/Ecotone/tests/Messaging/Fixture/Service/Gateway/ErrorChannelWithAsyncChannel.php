<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Service\Gateway;

use Ecotone\Api\CommandBus;
use Ecotone\Api\ErrorChannel;

#[ErrorChannel('async')]
interface ErrorChannelWithAsyncChannel extends CommandBus
{
}
