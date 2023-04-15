<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Application;

use App\ReactiveSystem\Stage_2\Domain\Order\ShippingAddress;
use Ramsey\Uuid\UuidInterface;

final class PlaceOrder
{
    public function __construct(
      public readonly UuidInterface $orderId,
      public readonly UuidInterface $userId,
      public readonly ShippingAddress $shippingAddress,
      public readonly UuidInterface $productId
    ) {}
}