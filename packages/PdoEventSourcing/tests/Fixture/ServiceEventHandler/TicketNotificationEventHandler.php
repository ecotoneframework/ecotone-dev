<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ServiceEventHandler;

use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryBus;
use Ecotone\Api\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;

/**
 * licence Apache-2.0
 */
final class TicketNotificationEventHandler
{
    private array $notifications = [];

    #[EventHandler]
    public function sendNotification(TicketWasRegistered $event, QueryBus $queryBus): void
    {
        $this->notifications[] = $queryBus->sendWithRouting('getInProgressTickets');
    }

    #[QueryHandler('getNotifications')]
    public function getNotifications(): array
    {
        return $this->notifications;
    }
}
