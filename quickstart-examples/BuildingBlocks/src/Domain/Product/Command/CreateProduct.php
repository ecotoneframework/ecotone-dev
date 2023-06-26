<?php

declare(strict_types=1);

namespace App\Domain\Product\Command;

use Money\Money;
use Ramsey\Uuid\UuidInterface;

final class CreateProduct
{
    public function __construct(
        public UuidInterface $productId,
        public string $name,
        public Money $price
    ) {}
}