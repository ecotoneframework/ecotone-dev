<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Product;

use Monorepo\ExampleApp\Common\Domain\Money;

final class ProductDetails
{
    public function __construct(
        public string $productName,
        public Money $productPrice
    ) {}
}