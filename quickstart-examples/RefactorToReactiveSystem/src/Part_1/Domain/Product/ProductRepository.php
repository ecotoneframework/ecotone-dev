<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Domain\Product;

use Ramsey\Uuid\UuidInterface;

interface ProductRepository
{
    public function getBy(UuidInterface $productId): Product;
}