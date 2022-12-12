<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ramsey\Uuid\UuidInterface;
interface ProductService
{
    #[MessageGateway("product.getPrice")]
    public function getPrice(#[AggregateIdentifier] UuidInterface $productId): int;
}