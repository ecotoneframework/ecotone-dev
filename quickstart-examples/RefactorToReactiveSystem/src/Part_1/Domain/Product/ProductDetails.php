<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Domain\Product;

use Money\Money;

final class ProductDetails
{
    public function __construct(private string $productName, private Money $productPrice) {}

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getProductPrice(): Money
    {
        return $this->productPrice;
    }
}