<?php

declare(strict_types=1);

namespace Test\App\Fixture;

use App\Testing\Domain\ShoppingBasket\ProductService;
use Ramsey\Uuid\UuidInterface;

final class StubProductService implements ProductService
{
    public function __construct(private int $productPrice) {}

    public function getPrice(UuidInterface $productId): int
    {
        return $this->productPrice;
    }
}