<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command;

class PlaceOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $product,
        public readonly int $quantity,
        public readonly bool $fail = false
    ) {
    }
}
