<?php

declare(strict_types=1);

namespace App\Domain\Product\Event;

use Money\Money;
use Ramsey\Uuid\UuidInterface;

final readonly class ProductWasAdded
{
    public function __construct(
        public UuidInterface $productId,
        public string $name,
        public Money $price
    ) {}
}