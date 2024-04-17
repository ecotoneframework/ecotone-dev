<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Payment\Command;

use Money\Money;

final readonly class TakePayment
{
    public function __construct(
        public string $orderId,
        public Money $amount
    ) {
    }
}