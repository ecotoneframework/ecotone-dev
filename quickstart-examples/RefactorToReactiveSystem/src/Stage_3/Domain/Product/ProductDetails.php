<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Product;

use Money\Money;

final class ProductDetails
{
    public function __construct(
        public readonly string $productName,
        public readonly Money $productPrice
    ) {}
}