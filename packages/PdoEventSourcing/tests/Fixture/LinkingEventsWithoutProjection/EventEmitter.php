<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection;

use Ecotone\EventSourcing\EventStreamEmitter;
use Ecotone\Modelling\Attribute\EventHandler;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;

final class EventEmitter
{
    #[EventHandler(endpointId: 'inProgressTicketList.addTicket')]
    public function addTicket(TicketWasRegistered $event, EventStreamEmitter $eventStreamEmitter): void
    {
        $eventStreamEmitter->linkTo(NotificationService::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
    }

    #[EventHandler(endpointId: 'inProgressTicketList.closeTicket')]
    public function closeTicket(TicketWasClosed $event, EventStreamEmitter $eventStreamEmitter): void
    {
        $eventStreamEmitter->linkTo(NotificationService::STREAM_NAME, [new TicketListUpdated($event->getTicketId())]);
    }
}
