<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Order\Command;

use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
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