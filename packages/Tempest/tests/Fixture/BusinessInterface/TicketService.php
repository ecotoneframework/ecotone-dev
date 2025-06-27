<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\BusinessInterface;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Tempest\Container\Singleton;

/**
 * licence Apache-2.0
 */
#[Singleton]
final class TicketService
{
    private static array $tickets = [];

    #[CommandHandler('ticket.create')]
    public function createTicket(CreateTicketCommand $command): void
    {
        $ticketId = 'ticket_' . uniqid();
        $ticket = Ticket::create($ticketId, $command->title, $command->description, $command->priority);
        
        self::$tickets[$ticketId] = $ticket;
    }

    #[QueryHandler('ticket.get_by_id')]
    public function getTicket(GetTicketQuery $query): ?Ticket
    {
        return self::$tickets[$query->ticketId] ?? null;
    }

    #[CommandHandler('ticket.close')]
    public function closeTicket(string $ticketId): void
    {
        if (isset(self::$tickets[$ticketId])) {
            self::$tickets[$ticketId]->close();
        }
    }

    #[QueryHandler('ticket.list_all')]
    public function listAllTickets(): array
    {
        return array_values(self::$tickets);
    }

    public static function reset(): void
    {
        self::$tickets = [];
    }

    public static function getTickets(): array
    {
        return self::$tickets;
    }
}
