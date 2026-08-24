<?php

declare(strict_types=1);

namespace Test\Ecotone\Messaging\Fixture\Scheduling;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\Delayed;
use Ecotone\Api\EventHandler;

/**
 * licence Apache-2.0
 */
class NotificationService
{
    #[Asynchronous('notifications')]
    #[Delayed(1000 * 60)] // 60 seconds
    #[EventHandler(endpointId: 'notifyOrderWasPlaced')]
    public function notify(OrderWasPlaced $event, CustomNotifier $notifier): void
    {
        $notifier->notify('placedOrder', $event->orderId);
    }
}
