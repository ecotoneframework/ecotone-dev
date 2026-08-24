<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\EventSourcing\Api\Attribute\Stream;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[Stream(self::STREAM_NAME)]
class Ticket
{
    use WithAggregateVersioning;

    public const STREAM_NAME = 'ticket_stream_for_projecting_tests';
    public const ASSIGN_COMMAND = 'ticket.assign_ticket';
    public const UNASSIGN_COMMAND = 'ticket.unassign_ticket';

    #[Identifier]
    public string $ticketId;

    #[CommandHandler]
    public static function create(CreateTicketCommand $command): array
    {
        return [new TicketCreated($command->ticketId)];
    }

    #[CommandHandler(self::ASSIGN_COMMAND)]
    public function assign(): array
    {
        return [new TicketAssigned($this->ticketId)];
    }

    #[CommandHandler(self::UNASSIGN_COMMAND)]
    public function unassign(): array
    {
        return [new TicketUnassigned($this->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyTicketCreated(TicketCreated $event): void
    {
        $this->ticketId = $event->ticketId;
    }
}
