<?php

namespace Fixture\Car;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface IncreaseSpeedGateway
{
    public const CHANNEL_NAME = 'speedChannel';

    #[MessageGateway(IncreaseSpeedGateway::CHANNEL_NAME)]
    public function increaseSpeed(int $amount): void;
}
