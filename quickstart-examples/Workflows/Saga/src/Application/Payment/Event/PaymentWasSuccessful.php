<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Payment\Event;

final readonly class PaymentWasSuccessful
{
    public function __construct(
        public string $orderId
    ) {
    }
}