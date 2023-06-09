<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Product;

use Monorepo\ExampleApp\Common\Domain\Money;

final class ProductDetails
{
    public function __construct(
        public readonly string $productName,
        public readonly Money $productPrice
    ) {}
}