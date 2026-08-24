<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedEventBus\Receiver;

use Ecotone\Api\Distributed;
use Ecotone\Api\EventBus;
use Ecotone\Api\EventHandler;
use Ecotone\Api\Header;
use Ecotone\Api\QueryHandler;
use RuntimeException;

/**
 * licence Apache-2.0
 */
class TicketServiceReceiver
{
    public const GET_TICKETS_COUNT      = 'getTicketsCount';
    public const GET_TICKETS      = 'getTickets';

    private array $tickets = [];

    #[Distributed]
    #[EventHandler('userService.*')]
    public function registerTicket(
        string $ticket,
        EventBus $eventBus,
        #[Header('shouldThrowException')] bool $shouldThrowException = false,
    ): void {
        $this->tickets[] = $ticket;
        $eventBus->publish(new TicketRegistered($ticket));

        if ($shouldThrowException) {
            throw new RuntimeException('Should throw exception');
        }
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
