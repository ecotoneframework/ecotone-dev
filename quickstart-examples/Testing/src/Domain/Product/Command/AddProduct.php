<?php

declare(strict_types=1);

namespace App\Testing\Domain\Product\Command;

use Ramsey\Uuid\UuidInterface;

final class AddProduct
{
    public function __construct(
        private UuidInterface $productId,
        private string $name,
        private int $price
    )
    {}

    public function getProductId(): UuidInterface
    {
        return $this->productId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrice(): int
    {
        return $this->price;
    }
}