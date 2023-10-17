<?php

declare(strict_types=1);

namespace App\Domain\Order\Event;

use Ramsey\Uuid\UuidInterface;

final class OrderWasPlaced
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