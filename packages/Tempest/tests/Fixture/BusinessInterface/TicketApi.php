<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\BusinessInterface;

use Ecotone\Messaging\Attribute\BusinessMethod;

/**
 * Business Interface for Ticket operations
 * licence Apache-2.0
 */
interface TicketApi
{
    #[BusinessMethod('ticket.create')]
    public function createTicket(CreateTicketCommand $command): void;

    #[BusinessMethod('ticket.get_by_id')]
    public function getTicket(GetTicketQuery $query): Ticket;

    #[BusinessMethod('ticket.close')]
    public function closeTicket(string $ticketId): void;

    #[BusinessMethod('ticket.list_all')]
    public function listAllTickets(): array;
}
