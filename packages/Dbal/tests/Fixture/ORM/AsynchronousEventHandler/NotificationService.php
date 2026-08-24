<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\AsynchronousEventHandler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonRegistered;

/**
 * licence Apache-2.0
 */
final class NotificationService
{
    private bool $isNotified = false;

    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'personNotifierPersonRegistered')]
    public function handle(PersonRegistered $event): void
    {
        $this->isNotified = true;
    }

    #[QueryHandler('notification.isNotified')]
    public function isNotified(): bool
    {
        return $this->isNotified;
    }
}
