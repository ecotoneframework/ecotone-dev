<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\LinkingEventsWithoutProjection;

use Ecotone\Api\EventHandler;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\EventSourcing\EventStreamEmitter;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;

/**
 * licence Apache-2.0
 */
#[Stream(NotificationService::STREAM_NAME)]
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
