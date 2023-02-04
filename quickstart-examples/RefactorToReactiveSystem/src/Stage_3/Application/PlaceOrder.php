<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Application;

use App\ReactiveSystem\Stage_3\Domain\Order\ShippingAddress;
use Ramsey\Uuid\UuidInterface;

final class PlaceOrder
{
    /** @param UuidInterface[] $productIds */
    public function __construct(
      public readonly ShippingAddress $address,
      public readonly array           $productIds
    ) {}
}