<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\Domain\Notification;

use App\ReactiveSystem\Stage_1\Domain\Product\ProductDetails;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

final class OrderConfirmationNotification
{
    /** @param ProductDetails[] $productDetails */
    public function __construct(
        public readonly string $userFullName,
        public readonly UuidInterface $orderId,
        public readonly array $productDetails,
        public readonly Money $totalAmount
    ) {}
}