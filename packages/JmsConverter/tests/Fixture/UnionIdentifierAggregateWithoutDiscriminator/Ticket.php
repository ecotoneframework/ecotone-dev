<?php

namespace Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregateWithoutDiscriminator;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\Attribute\Identifier;
use Ecotone\Modelling\WithAggregateVersioning;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\ExternalId;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalId;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
final class Ticket
{
    use WithAggregateVersioning;

    #[Identifier]
    private InternalId|ExternalId $ticketId;

    private bool $closed = false;

    #[CommandHandler]
    public static function create(CreateTicket $command): array
    {
        return [new TicketWasCreated($command->ticketId)];
    }

    #[CommandHandler]
    public function close(CloseTicket $command): array
    {
        return [new TicketWasClosed((string) $this->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyCreated(TicketWasCreated $event): void
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
