<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Product;

use Ramsey\Uuid\UuidInterface;

interface ProductRepository
{
    public function getBy(UuidInterface $productId): Product;
}