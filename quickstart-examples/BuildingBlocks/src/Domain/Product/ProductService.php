<?php

declare(strict_types=1);

namespace App\Domain\Product;

use Ecotone\Messaging\Attribute\MessageGateway;
use Ecotone\Modelling\Attribute\AggregateIdentifier;
use Money\Money;

/**
 * Message Gateways enables convenient way to expose your Message Handlers as a service
 *
 * @link https://docs.ecotone.tech/messaging/messaging-concepts/messaging-gateway
 */
interface ProductService
{
    #[MessageGateway("product.getPrice")]
    public function getPrice(#[AggregateIdentifier] $productId): Money;
}