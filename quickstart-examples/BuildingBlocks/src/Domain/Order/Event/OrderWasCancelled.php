<?php

declare(strict_types=1);

namespace App\Domain\Order\Event;

use Ramsey\Uuid\UuidInterface;

final class OrderWasCancelled
{
    /**
     * @param UuidInterface[] $productIds
     */
    public function __construct(
        public UuidInterface $orderId,
    )
    {}
}