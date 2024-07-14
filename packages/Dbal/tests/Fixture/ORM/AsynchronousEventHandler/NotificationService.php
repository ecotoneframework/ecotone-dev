<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\AsynchronousEventHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
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
