<?php

declare(strict_types=1);

namespace App\Sales;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('ticketing.hold_expired')]
final readonly class HoldExpired
{
    public function __construct(
        public string $orderId,
        #[EventTag('concert')]
        public string $concertId,
        #[EventTag('seat')]
        public string $seatId,
        public string $customerId,
    ) {
    }

    #[EventTag('customerConcert')]
    public function customerConcert(): string
    {
        return CustomerConcertSeats::scopeOf(customerId: $this->customerId, concertId: $this->concertId);
    }
}
