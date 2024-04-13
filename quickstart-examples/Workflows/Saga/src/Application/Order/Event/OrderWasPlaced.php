<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Order\Event;

final readonly class OrderWasPlaced
{
    public function __construct(
        public string $orderId,
    ) {
    }
}