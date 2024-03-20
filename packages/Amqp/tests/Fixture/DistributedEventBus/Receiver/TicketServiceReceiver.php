<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver;

use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\Distributed;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;

class TicketServiceReceiver
{
    public const GET_TICKETS_COUNT      = 'getTicketsCount';

    private array $tickets = [];

    #[Distributed]
    #[EventHandler('userService.*')]
    public function registerTicket(
        string $ticket,
        EventBus $eventBus,
        #[Header('shouldThrowException')] bool $shouldThrowException = false,
    ): void
    {
        $this->tickets[] = $ticket;
        $eventBus->publish(new TicketRegistered($ticket));

        if ($shouldThrowException) {
            throw new \RuntimeException('Should throw exception');
        }
    }

    #[QueryHandler(self::GET_TICKETS_COUNT)]
    public function getTickets(): int
    {
        return count($this->tickets);
    }
}
