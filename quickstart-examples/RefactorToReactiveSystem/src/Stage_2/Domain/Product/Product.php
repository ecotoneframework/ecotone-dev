<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Domain\Product;

use Ramsey\Uuid\UuidInterface;

final class Product
{
    public function __construct(private UuidInterface $productId, private ProductDetails $productDetails)
    {
    }

    public function getProductId(): UuidInterface
    {
        return $this->productId;
    }

    public function getProductDetails(): ProductDetails
    {
        return $this->productDetails;
    }
}