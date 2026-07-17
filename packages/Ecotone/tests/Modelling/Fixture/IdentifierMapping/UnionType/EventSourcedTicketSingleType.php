<?php

declare(strict_types=1);

namespace Test\Ecotone\Modelling\Fixture\IdentifierMapping\UnionType;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
final class EventSourcedTicketSingleType
{
    use WithAggregateVersioning;

    #[Identifier]
    private InternalId $ticketId;

    private bool $closed = false;

    #[CommandHandler]
    public static function create(CreateTicketSingleType $command): array
    {
        return [new TicketWasCreatedSingleType($command->ticketId)];
    }

    #[CommandHandler]
    public function close(CloseTicket $command): array
    {
        return [new TicketWasClosed((string) $this->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyCreated(TicketWasCreatedSingleType $event): void
    {
        $this->ticketId = $event->ticketId;
    }

    #[EventSourcingHandler]
    public function applyClosed(TicketWasClosed $event): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
