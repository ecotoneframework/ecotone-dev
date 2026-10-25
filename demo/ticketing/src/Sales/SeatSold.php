<?php

declare(strict_types=1);

namespace App\Sales;

use App\Catalogue\Tier;
use Ecotone\Api\Attribute\EventTag;
use Ecotone\Api\Attribute\NamedEvent;

#[NamedEvent('ticketing.seat_sold')]
final readonly class SeatSold
{
    public function __construct(
        public string $orderId,
        #[EventTag('concert')]
        public string $concertId,
        #[EventTag('seat')]
        public string $seatId,
        #[EventTag('customer')]
        public string $customerId,
        public string $paymentReference,
        public Tier $tier,
        public int $priceCents,
    ) {
    }
}
