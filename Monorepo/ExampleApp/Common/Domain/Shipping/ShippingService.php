<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Shipping;

use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Domain\Product\ProductDetails;
use Ramsey\Uuid\UuidInterface;

interface ShippingService
{
    public function shipOrderFor(UuidInterface $userId, UuidInterface $orderId, ProductDetails $productDetails, ShippingAddress $shippingAddress): void;
}