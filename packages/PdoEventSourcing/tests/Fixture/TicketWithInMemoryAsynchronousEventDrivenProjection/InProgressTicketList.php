<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection;

use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionDelete;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\EventSourcing\Attribute\ProjectionReset;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Asynchronous('asynchronous_projections')]
#[Projection(self::IN_PROGRESS_TICKET_PROJECTION, Ticket::class)]
class InProgressTicketList
{
    public const IN_PROGRESS_TICKET_PROJECTION = 'inProgressTicketList';

    private array $tickets = [];

    #[QueryHandler('getInProgressTickets')]
    public function getTickets(): array
    {
        return $this->tickets;
    }

    #[EventHandler(endpointId: 'inProgressTicketList.addTicket')]
    public function addTicket(TicketWasRegistered $event): void
    {
        $this->tickets[$event->getTicketId()] = [
            'ticket_id' => $event->getTicketId(),
            'ticket_type' => $event->getTicketType(),
        ];
    }

    #[EventHandler(endpointId: 'inProgressTicketList.closeTicket')]
    public function closeTicket(TicketWasClosed $event): void
    {
        unset($this->tickets[$event->getTicketId()]);
    }

    #[ProjectionInitialization]
    public function initialization(): void
    {
        $this->tickets = [];
    }

    #[ProjectionDelete]
    public function delete(): void
    {
    }

    #[ProjectionReset]
    public function reset(): void
    {
        $this->tickets = [];
    }
}
