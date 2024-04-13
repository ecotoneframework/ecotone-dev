<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Payment;

use Money\Money;

interface PaymentProcessor
{
    /**
     * @return bool Whether the payment was successful
     */
    public function takePayment(string $orderId, Money $amount): bool;
}