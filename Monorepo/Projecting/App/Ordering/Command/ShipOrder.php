<?php
declare(strict_types=1);

namespace Monorepo\Projecting\App\Ordering\Command;

class ShipOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly bool $fail = false
    ) {}
}
