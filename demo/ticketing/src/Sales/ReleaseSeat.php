<?php

declare(strict_types=1);

namespace App\Sales;

final readonly class ReleaseSeat
{
    public function __construct(
        public string $orderId,
        public string $concertId,
        public string $seatId,
        public string $customerId,
    ) {
    }
}
