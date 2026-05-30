<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Order;

/**
 * licence Apache-2.0
 */
final class PlaceOrder
{
    public function __construct(
        public readonly string $userId,
        public readonly int $totalPrice,
    ) {
    }
}
