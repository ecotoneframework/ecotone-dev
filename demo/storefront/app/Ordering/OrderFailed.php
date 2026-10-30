<?php

declare(strict_types=1);

namespace App\Ordering;

final class OrderFailed
{
    /**
     * @param array<string, string> $holdIdsOfHeldSeats
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $concertId,
        public readonly string $customerId,
        public readonly array $holdIdsOfHeldSeats,
        public readonly ?string $paymentReference,
        public readonly string $reason,
    ) {
    }
}
