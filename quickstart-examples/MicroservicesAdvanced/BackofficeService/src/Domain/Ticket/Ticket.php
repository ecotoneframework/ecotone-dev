<?php

namespace App\Microservices\BackofficeService\Domain\Ticket;

use App\Microservices\BackofficeService\Domain\Ticket\Command\AssignTicket;
use App\Microservices\BackofficeService\Domain\Ticket\Command\PrepareTicket;
use App\Microservices\BackofficeService\Domain\Ticket\Event\TicketWasAssigned;
use App\Microservices\BackofficeService\Domain\Ticket\Event\TicketWasCancelled;
use App\Microservices\BackofficeService\Domain\Ticket\Event\TicketWasPrepared;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\WithAggregateVersioning;
use Ramsey\Uuid\Uuid;

#[EventSourcingAggregate]
class Ticket
{
    const PREPARE_TICKET_TICKET = "ticket.prepareTicket";
    const CANCEL_TICKET         = "ticket.cancel";

    use WithAggregateVersioning;

    #[AggregateIdentifier]
    private string $ticketId;
    private bool $isCancelled;
    private bool $isAssigned;

    #[Distributed]
    #[CommandHandler(self::PREPARE_TICKET_TICKET)]
    public static function prepare(PrepareTicket $command): array
    {
        return [new TicketWasPrepared($command->ticketId ?: Uuid::uuid4()->toString(), $command->ticketType, $command->description)];
    }

    #[Distributed]
    #[CommandHandler(self::CANCEL_TICKET)]
    public function cancel(): array
    {
        if ($this->isCancelled) {
            return [];
        }

        return [new TicketWasCancelled($this->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyTicketWasPrepared(TicketWasPrepared $event): void
    {
        $this->ticketId    = $event->getTicketId();
        $this->isCancelled = false;
        $this->isAssigned  = false;
    }

    #[EventSourcingHandler]
    public function applyTicketWasCancelled(TicketWasCancelled $event): void
    {
        $this->isCancelled = true;
    }
}
