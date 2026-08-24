<?php

namespace Test\Ecotone\EventSourcing\Fixture\TicketWithInMemoryAsynchronousEventDrivenProjection;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\ProjectionDelete;
use Ecotone\Api\Attribute\ProjectionInitialization;
use Ecotone\Api\Attribute\ProjectionReset;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\EventSourcing\Attribute\Projection;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasClosed;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Event\TicketWasRegistered;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Ticket;

#[Asynchronous('asynchronous_projections')]
#[Projection(self::IN_PROGRESS_TICKET_PROJECTION, Ticket::class)]
/**
 * licence Apache-2.0
 */
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
