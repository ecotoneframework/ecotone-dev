<?php

declare(strict_types=1);

namespace App\Ordering;

final class RecordSeatRefusal
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $seatId,
        public readonly string $holdId,
        public readonly string $reason,
    ) {
    }
}
