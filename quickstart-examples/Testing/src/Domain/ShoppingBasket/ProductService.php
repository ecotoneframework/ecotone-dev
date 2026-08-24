<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Api\BusinessMethod;
use Ecotone\Api\Identifier;
use Ramsey\Uuid\UuidInterface;
interface ProductService
{
    #[BusinessMethod("product.getPrice")]
    public function getPrice(#[Identifier] UuidInterface $productId): int;
}