<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering\Command;

class CancelOrder
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $reason,
        public readonly bool $fail = false
    ) {
    }
}
