<?php

declare(strict_types=1);

namespace App\Sales;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('ticketing.seats_sold_to_order')]
final readonly class SeatsSoldToOrder
{
    /**
     * @param list<SoldSeat> $seats
     */
    public function __construct(
        public string $orderId,
        #[EventTag('concert')]
        public string $concertId,
        public string $customerId,
        public string $paymentReference,
        public array $seats,
    ) {
    }
}
