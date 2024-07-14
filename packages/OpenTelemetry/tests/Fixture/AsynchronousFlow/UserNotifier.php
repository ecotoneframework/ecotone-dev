<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

/**
 * licence Apache-2.0
 */
final class UserNotifier
{
    #[Asynchronous('async_channel')]
    #[EventHandler(endpointId: 'user.registered')]
    public function handle(UserRegistered $event): void
    {

    }
}
