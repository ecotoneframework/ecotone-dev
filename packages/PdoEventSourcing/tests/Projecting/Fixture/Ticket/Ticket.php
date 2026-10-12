<?php

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\Fixture\Ticket;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\EventSourcingHandler;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Api\Modelling\WithAggregateVersioning;

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
