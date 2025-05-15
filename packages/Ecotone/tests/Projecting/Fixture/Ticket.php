<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Test\Ecotone\Projecting\Fixture;

use Ecotone\EventSourcing\Attribute\Stream;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
#[Stream(self::STREAM_NAME)]
class Ticket
{
    use WithAggregateVersioning;

    public const STREAM_NAME = 'ticket_stream_for_projecting_tests';
    public const ASSIGN_COMMAND = 'ticket.assign_ticket';

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

    #[EventSourcingHandler]
    public function applyTicketCreated(TicketCreated $event): void
    {
        $this->ticketId = $event->ticketId;
    }
}