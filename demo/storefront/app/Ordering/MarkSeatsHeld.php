<?php

declare(strict_types=1);

namespace App\Ordering;

final class MarkSeatsHeld
{
    /**
     * @param list<string> $seatIds
     */
    public function __construct(
        public readonly string $orderId,
        public readonly array $seatIds,
    ) {
    }
}
