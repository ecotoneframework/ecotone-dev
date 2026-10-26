<?php

declare(strict_types=1);

namespace App\Issuance;

use App\Sales\SeatsSoldToOrder;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;

final class TicketIssuanceStart
{
    #[Asynchronous('ticketing_async')]
    #[EventHandler(endpointId: 'start_ticket_issuance_of_sold_order')]
    public function whenSeatsSoldToOrder(SeatsSoldToOrder $event, TicketIssuanceProcess $ticketIssuance): void
    {
        $ticketIssuance->issue(
            new IssueTickets(orderId: $event->orderId, concertId: $event->concertId, customerId: $event->customerId, seats: $event->seats),
            $event->concertId,
        );
    }
}
