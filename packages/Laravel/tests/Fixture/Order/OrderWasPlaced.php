<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

final class OrderWasPlaced
{
    public function __construct(
        public int $orderId,
        public string $userId,
    ) {
    }
}
