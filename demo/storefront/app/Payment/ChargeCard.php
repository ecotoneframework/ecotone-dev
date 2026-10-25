<?php

declare(strict_types=1);

namespace App\Payment;

final class ChargeCard
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $paymentReference,
        public readonly string $cardEnding,
    ) {
    }
}
