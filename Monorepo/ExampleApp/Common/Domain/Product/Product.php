<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Product;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Ecotone\Modelling\Attribute\Identifier;
use Ramsey\Uuid\UuidInterface;

#[Aggregate]
final class Product
{
    public function __construct(#[Identifier] private UuidInterface $productId, private ProductDetails $productDetails)
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