<?php

namespace App\Domain;

use App\Domain\Command\RegisterNewTicket;
use App\Domain\Command\SubtractMoneyFromWallet;
use App\Domain\Event\MoneyWasAddedToWallet;
use App\Domain\Event\MoneyWasSubtractedFromWallet;
use App\Domain\Event\TicketWasRegistered;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventSourcingAggregate;
use Ecotone\Modelling\Attribute\EventSourcingHandler;
use Ecotone\Modelling\WithAggregateVersioning;

#[EventSourcingAggregate]
final class Ticket
{
    use WithAggregateVersioning;

    #[AggregateIdentifier]
    private string $ticketId;

    #[CommandHandler]
    public static function register(RegisterNewTicket $command): array
    {
        return [new TicketWasRegistered($command->ticketId)];
    }

    #[EventSourcingHandler]
    public function applyWalletWasInitialized(TicketWasRegistered $event): void
    {
        $this->ticketId = $event->ticketId;
    }
}