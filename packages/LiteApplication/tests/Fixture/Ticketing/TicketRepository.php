<?php

declare(strict_types=1);

namespace Test\Ecotone\Lite\Fixture\Ticketing;

use Ecotone\Modelling\Attribute\Repository;

/**
 * licence Apache-2.0
 */
interface TicketRepository
{
    #[Repository]
    public function getBy(string $ticketId): Ticket;
}
