<?php

declare(strict_types=1);

namespace App\Ordering;

final class SeatRefused
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $concertId,
        public readonly string $customerId,
        public readonly string $seatId,
        public readonly string $holdId,
        public readonly string $reason,
    ) {
    }
}
