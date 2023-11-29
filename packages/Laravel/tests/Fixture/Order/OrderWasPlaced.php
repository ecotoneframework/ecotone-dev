<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

final readonly class OrderWasPlaced
{
    public function __construct(
        public string $orderId,
        public string $userId,
    ) {}
}