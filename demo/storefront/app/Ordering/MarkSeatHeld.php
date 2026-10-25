<?php

declare(strict_types=1);

namespace App\Ordering;

final class MarkSeatHeld
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $seatId,
        public readonly string $expiresAt,
    ) {
    }
}
