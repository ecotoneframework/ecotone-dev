<?php

declare(strict_types=1);

namespace App\Sales;

use DateTimeImmutable;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('ticketing.seat_held')]
final readonly class SeatHeld
{
    public function __construct(
        public string $orderId,
        #[EventTag('concert')]
        public string $concertId,
        #[EventTag('seat')]
        public string $seatId,
        public string $customerId,
        public string $holdId,
        public DateTimeImmutable $heldAt,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    #[EventTag('customerConcert')]
    public function customerConcert(): string
    {
        return CustomerConcertSeats::scopeOf(customerId: $this->customerId, concertId: $this->concertId);
    }
}
