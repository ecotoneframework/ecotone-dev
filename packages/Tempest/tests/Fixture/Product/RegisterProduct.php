<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Product;

/**
 * licence Apache-2.0
 */
final readonly class RegisterProduct
{
    public function __construct(
        public string $productId,
        public string $name,
        public float $price
    ) {
    }
}
