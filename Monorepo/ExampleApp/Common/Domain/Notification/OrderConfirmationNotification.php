<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Notification;

use Monorepo\ExampleApp\Common\Domain\Money;
use Monorepo\ExampleApp\Common\Domain\Product\ProductDetails;
use Ramsey\Uuid\UuidInterface;

final class OrderConfirmationNotification
{
    public function __construct(
        public readonly string $userFullName,
        public readonly UuidInterface $orderId,
        public readonly ProductDetails $productDetails,
        public readonly Money $totalAmount
    ) {}
}