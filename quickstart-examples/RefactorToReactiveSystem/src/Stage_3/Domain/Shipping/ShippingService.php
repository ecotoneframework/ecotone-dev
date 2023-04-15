<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Shipping;

use App\ReactiveSystem\Stage_3\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Stage_3\Domain\Product\ProductDetails;
use Ramsey\Uuid\UuidInterface;

interface ShippingService
{
    public function shipOrderFor(UuidInterface $userId, UuidInterface $orderId, ProductDetails $productDetails, ShippingAddress $shippingAddress): void;
}