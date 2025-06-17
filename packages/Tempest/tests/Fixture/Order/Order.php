<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Order;

/**
 * licence Apache-2.0
 */
final readonly class Order
{
    /**
     * @param string[] $productIds
     */
    public function __construct(
        public string $orderId,
        public string $userId,
        public array $productIds,
        public string $status = 'placed'
    ) {
    }

    public static function place(string $orderId, string $userId, array $productIds): self
    {
        return new self($orderId, $userId, $productIds, 'placed');
    }
}
