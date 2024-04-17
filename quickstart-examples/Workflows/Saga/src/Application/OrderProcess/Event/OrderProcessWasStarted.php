<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\OrderProcess\Event;

final readonly class OrderProcessWasStarted
{
    public function __construct(
        public string $orderId
    ) {
    }
}