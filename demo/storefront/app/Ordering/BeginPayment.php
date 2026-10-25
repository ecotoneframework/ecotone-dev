<?php

declare(strict_types=1);

namespace App\Ordering;

final class BeginPayment
{
    public function __construct(
        public readonly string $orderId,
    ) {
    }
}
