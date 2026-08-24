<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Handler\ClosureInAttribute;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Delayed;
use Ecotone\Api\Payload;

/**
 * licence Apache-2.0
 */
final class DelayedClosureService
{
    #[Delayed(expression: static function (#[Payload] DelayCommand $command): int {
        return $command->delay;
    })]
    #[Asynchronous('async')]
    #[CommandHandler('notification.delayed', endpointId: 'notificationDelayedEndpoint')]
    public function handle(DelayCommand $command): void
    {
    }
}
