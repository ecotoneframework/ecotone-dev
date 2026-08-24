<?php

declare(strict_types=1);

namespace Test\Ecotone\OpenTelemetry\Fixture\AsynchronousFlow;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;

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
