<?php

namespace Test\Ecotone\Messaging\Fixture\Distributed\DistributedEventBus\ReceiverTicketWithConversion;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\Message;
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;
use RuntimeException;
use Test\Ecotone\Messaging\Fixture\Distributed\DistributedEventBus\ReceiverTicket\TicketRegistered;

/**
 * licence Apache-2.0
 */
class TicketServiceReceiver
{
    public const GET_TICKETS_COUNT      = 'getTicketsCount';
    public const GET_TICKETS      = 'getTickets';

    private array $tickets = [];

    #[Distributed]
    #[EventHandler('userService.billing.DetailsWereChanged')]
    public function registerTicket(
        UserChangedAddress $event,
    ): void {
        $this->tickets[] = $event;
    }

    #[QueryHandler(self::GET_TICKETS_COUNT)]
    public function getTicketsCount(): int
    {
        return count($this->tickets);
    }

    #[QueryHandler(self::GET_TICKETS)]
    public function getTickets(): array
    {
        return $this->tickets;
    }
}
