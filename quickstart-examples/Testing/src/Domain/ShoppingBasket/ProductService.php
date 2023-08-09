<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Modelling\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;
interface ProductService
{
    #[MessageGateway("product.getPrice")]
    public function getPrice(#[Identifier] UuidInterface $productId): int;
}