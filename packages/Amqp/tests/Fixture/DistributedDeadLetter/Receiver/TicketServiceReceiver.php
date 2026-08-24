<?php

namespace Test\Ecotone\Amqp\Fixture\DistributedDeadLetter\Receiver;

use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Distributed;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Attribute\ServiceActivator;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class TicketServiceReceiver
{
    public const CREATE_TICKET_ENDPOINT  = 'createTicket';
    public const GET_ERROR_TICKETS_COUNT = 'getErrorTicketsCount';

    private array $tickets = [];

    #[Distributed]
    #[CommandHandler(self::CREATE_TICKET_ENDPOINT)]
    public function registerTicket(string $ticket): void
    {
        throw new InvalidArgumentException('Error during handling');
    }

    #[QueryHandler(self::GET_ERROR_TICKETS_COUNT)]
    public function getTickets(): int
    {
        return count($this->tickets);
    }

    #[ServiceActivator(TicketServiceMessagingConfiguration::DEAD_LETTER_CHANNEL)]
    public function registerErrorTicket(string $ticket): void
    {
        $this->tickets[] = $ticket;
    }
}
