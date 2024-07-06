<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\ServiceEventHandler;

use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\QueryBus;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;

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
