<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Shipment\Command;

final readonly class ShipOrder
{
    public function __construct(
        public string $orderId
    ) {
    }
}