<?php

namespace Test\Ecotone\Modelling\Fixture\CommandHandler\Aggregate;

use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

/**
 * licence Apache-2.0
 */
class OrderNotificator
{
    /** @var Notification[] */
    private $notifications = [];

    #[EventHandler]
    public function notify(Notification $notification): void
    {
        $this->notifications[] = $notification;
    }

    #[QueryHandler('getOrderNotifications')]
    public function getNotifications(array $query): array
    {
        return $this->notifications;
    }
}
