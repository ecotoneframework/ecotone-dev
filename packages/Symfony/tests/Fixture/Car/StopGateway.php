<?php

namespace Fixture\Car;

use Ecotone\Messaging\Attribute\MessageGateway;

/**
 * licence Apache-2.0
 */
interface StopGateway
{
    public const CHANNEL_NAME = 'stopChannel';

    #[MessageGateway(StopGateway::CHANNEL_NAME)]
    public function stop(): void;
}
