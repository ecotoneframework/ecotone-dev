<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\Fixture\TicketPure;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\AssignTicket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\CreateTicket;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasAssigned;
use Test\Ecotone\EventSourcingV2\Fixture\Ticket\TicketWasCreated;

#[EventSourcingAggregate]
#[EventSourced('ticket')]
class TicketPure
{
    use WithAggregateVersioning;

    #[Identifier]
    private string $ticketId;
    private string $assignee = '';

    #[CommandHandler]
    public static function create(CreateTicket $command): array
    {
        return [new TicketWasCreated($command->ticketId)];
    }

    #[CommandHandler]
    public function assign(AssignTicket $command): array
    {
        return [new TicketWasAssigned($command->ticketId, $command->assignee)];
    }

    public function getTicketId(): string
    {
        return $this->ticketId;
    }

    public function getAssignee(): string
    {
        return $this->assignee;
    }

    #[EventSourcingHandler]
    public function applyTicketWasCreated(TicketWasCreated $event): void
    {
        $this->ticketId = $event->id;
    }

    #[EventSourcingHandler]
    public function applyTicketWasAssigned(TicketWasAssigned $event): void
    {
        $this->assignee = $event->assignee;
    }
}