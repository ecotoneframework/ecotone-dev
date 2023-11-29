<?php

declare(strict_types=1);

namespace Test\Ecotone\Laravel\Fixture\Product;

use Money\Money;

final class RegisterProduct
{
    public function __construct(
        public string $id,
        public string $name,
        public Money  $price
    ) {}
}