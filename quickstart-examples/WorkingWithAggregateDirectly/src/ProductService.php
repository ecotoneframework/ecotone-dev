<?php

declare(strict_types=1);

namespace App\WorkingWithAggregateDirectly;

use App\WorkingWithAggregateDirectly\Command\ChangePrice;
use App\WorkingWithAggregateDirectly\Command\RegisterProduct;
use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Modelling\Attribute\AggregateIdentifier;

/**
 * Implementation will be auto-generated and this class will be available in your Dependency Container
 */
interface ProductService
{
    #[MessageGateway(Product::PRODUCT_REGISTER_API)]
    public function registerProduct(RegisterProduct $command): void;

    #[MessageGateway(Product::PRODUCT_CHANGE_PRICE_API)]
    public function changePrice(ChangePrice $command): void;

    #[MessageGateway(Product::PRODUCT_GET_PRICE_API)]
    public function getPrice(#[AggregateIdentifier] $productId): float;
}