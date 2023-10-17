<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Infrastructure;

use App\ReactiveSystem\Stage_2\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Stage_2\Domain\Product\ProductDetails;
use App\ReactiveSystem\Stage_2\Domain\Shipping\ShippingService;
use Ramsey\Uuid\UuidInterface;

final class StubShippingService implements ShippingService
{
    public function shipOrderFor(UuidInterface $userId, UuidInterface $orderId, ProductDetails $productDetails, ShippingAddress $shippingAddress): void
    {
        /** In production run we would Shipping Service over HTTP  */

        echo sprintf("\n Shipping products to %s %s! \n", $shippingAddress->street, $shippingAddress->houseNumber);
    }
}