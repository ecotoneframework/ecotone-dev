<?php

declare(strict_types=1);

namespace App\Sales;

final readonly class ConfirmSeedOrder
{
    /**
     * @param list<string> $seatIds
     */
    public function __construct(
        public string $orderId,
        public string $concertId,
        public string $customerId,
        public array $seatIds,
        public string $paymentReference,
    ) {
    }
}
