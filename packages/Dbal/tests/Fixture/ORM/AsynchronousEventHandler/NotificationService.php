<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\ORM\AsynchronousEventHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\Dbal\Fixture\ORM\Person\PersonRegistered;

final class NotificationService
{
    #[Asynchronous('notifications')]
    #[EventHandler(endpointId: 'personNotifierPersonRegistered')]
    public function handle(PersonRegistered $event): void
    {
        // do something
    }
}
