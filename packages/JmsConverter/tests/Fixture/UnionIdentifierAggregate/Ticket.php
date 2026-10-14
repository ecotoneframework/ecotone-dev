<?php

namespace Test\Ecotone\JMSConverter\Fixture\UnionIdentifierAggregate;

use Ecotone\Api\Attribute\Apply;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\EventSourcingAggregate;
use Ecotone\Api\Attribute\Identifier;
use Ecotone\Api\Modelling\WithAggregateVersioning;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\ExternalIdWithDiscriminator;
use Test\Ecotone\JMSConverter\Fixture\ExamplesToConvert\UnionType\InternalIdWithDiscriminator;

#[EventSourcingAggregate]
/**
 * licence Apache-2.0
 */
final class Ticket
{
    use WithAggregateVersioning;

    #[Identifier]
    private InternalIdWithDiscriminator|ExternalIdWithDiscriminator $ticketId;

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

    #[Apply]
    public function applyCreated(TicketWasCreated $event): void
    {
        $this->ticketId = $event->ticketId;
    }

    #[Apply]
    public function applyClosed(TicketWasClosed $event): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
