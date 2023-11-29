<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Order;

final readonly class PlaceOrder
{
    /**
     * @param int[] $productIds
     */
    public function __construct(
        public string $userId,
        public array $productIds
    ) {
        
    }
}