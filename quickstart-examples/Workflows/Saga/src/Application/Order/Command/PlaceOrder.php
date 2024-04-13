<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Order\Command;

use App\Workflow\Saga\Application\Order\Item;

final readonly class PlaceOrder
{
    /**
     * @param Item[] $items
     */
    public function __construct(
        public string $orderId,
        public string $customerId,
        public array $items
    ) {
    }
}