<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixture\Ticketing;

use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class TicketNotifier
{
    public function __construct(private TicketRepository $ticketRepository)
    {
    }

    #[QueryHandler('ticket.getRegistered')]
    public function getRegistered(string $ticketId): string
    {
        return $this->ticketRepository->getBy($ticketId)->getId();
    }
}
