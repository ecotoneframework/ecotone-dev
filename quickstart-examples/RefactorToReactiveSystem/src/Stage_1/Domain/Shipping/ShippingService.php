<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\Domain\Shipping;

use App\ReactiveSystem\Stage_1\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Stage_1\Domain\Product\ProductDetails;
use Ramsey\Uuid\UuidInterface;

interface ShippingService
{
    /**
     * @param ProductDetails[] $productDetails
     */
    public function shipOrderFor(UuidInterface $userId, UuidInterface $orderId, array $productDetails, ShippingAddress $shippingAddress): void;
}