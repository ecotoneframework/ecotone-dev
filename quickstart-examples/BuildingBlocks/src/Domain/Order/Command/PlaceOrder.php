<?php

declare(strict_types=1);

namespace App\Domain\Order\Command;

use Ramsey\Uuid\UuidInterface;

final readonly class PlaceOrder
{
    /**
     * @param UuidInterface[] $productIds
     */
    public function __construct(
        public UuidInterface $orderId,
        public array $productIds
    )
    {}
}