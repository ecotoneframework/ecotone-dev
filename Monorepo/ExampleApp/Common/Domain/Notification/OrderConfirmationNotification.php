<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Notification;

use Monorepo\ExampleApp\Common\Domain\Money;
use Monorepo\ExampleApp\Common\Domain\Product\ProductDetails;
use Ramsey\Uuid\UuidInterface;

final class OrderConfirmationNotification
{
    public function __construct(
        public string $userFullName,
        public UuidInterface $orderId,
        public ProductDetails $productDetails,
        public Money $totalAmount
    ) {}
}