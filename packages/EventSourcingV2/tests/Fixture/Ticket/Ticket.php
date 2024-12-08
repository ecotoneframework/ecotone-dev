<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\Fixture\Ticket;

use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourced;
use Ecotone\EventSourcingV2\Ecotone\Attribute\EventSourcingHandler;
use Ecotone\EventSourcingV2\Ecotone\WithEventSourcingAttributes;
use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
#[EventSourced('ticket')]
class Ticket
{
    use WithEventSourcingAttributes;

    #[Identifier]
    private string $ticketId;
    private string $assignee = '';

    #[CommandHandler]
    public static function create(CreateTicket $command): self
    {
        $ticket = new self();
        $ticket->apply(new TicketWasCreated($command->ticketId));
        return $ticket;
    }

    #[CommandHandler]
    public function assign(AssignTicket $command): void
    {
        $this->apply(new TicketWasAssigned($command->ticketId, $command->assignee));
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
    protected function applyTicketWasCreated(TicketWasCreated $event): void
    {
        $this->ticketId = $event->id;
    }

    #[EventSourcingHandler]
    protected function applyTicketWasAssigned(TicketWasAssigned $event): void
    {
        $this->assignee = $event->assignee;
    }
}