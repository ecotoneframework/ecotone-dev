<?php

declare(strict_types=1);

namespace App\Payment;

final class Pay
{
    public function __construct(
        public readonly string $orderId,
        public readonly PaymentOutcome $outcome,
    ) {
    }
}
