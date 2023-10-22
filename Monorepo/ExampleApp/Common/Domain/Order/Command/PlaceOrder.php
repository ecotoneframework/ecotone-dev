<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Order\Command;

use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Ramsey\Uuid\UuidInterface;

final class PlaceOrder
{
    public function __construct(
      public UuidInterface $orderId,
      public UuidInterface $userId,
      public ShippingAddress $shippingAddress,
      public UuidInterface $productId
    ) {}
}