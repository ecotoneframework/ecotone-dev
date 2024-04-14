<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Payment;

use Money\Money;

final class PaymentProcessor
{
    public function __construct(private int $successAfterAttempt = 1, private int $attempt = 0)
    {
    }

    public function takePayment(string $orderId, Money $amount): bool
    {
        $this->attempt++;
        if ($this->attempt < $this->successAfterAttempt) {
            return false;
        }

        return true;
    }
}