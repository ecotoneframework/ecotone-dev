<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Product;

/**
 * licence Apache-2.0
 */
final readonly class Product
{
    public function __construct(
        public string $productId,
        public string $name,
        public float $price
    ) {
    }

    public static function register(string $productId, string $name, float $price): self
    {
        return new self($productId, $name, $price);
    }
}
