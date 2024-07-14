<?php

namespace Fixture\Car;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface GetSpeedGateway
{
    public const CHANNEL_NAME = 'getSpeedChannel';

    #[MessageGateway(GetSpeedGateway::CHANNEL_NAME)]
    public function getSpeed(): int;
}
