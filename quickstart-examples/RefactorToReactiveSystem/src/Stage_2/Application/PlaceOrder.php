<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Application;

use App\ReactiveSystem\Stage_2\Domain\Order\ShippingAddress;
use Ramsey\Uuid\UuidInterface;

final class PlaceOrder
{
    /** @param UuidInterface[] $productIds */
    public function __construct(
      public readonly UuidInterface $userId,
      public readonly ShippingAddress $shippingAddress,
      public readonly array $productIds
    ) {}
}