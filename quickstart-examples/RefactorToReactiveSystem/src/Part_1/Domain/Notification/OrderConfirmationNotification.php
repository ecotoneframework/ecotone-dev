<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Domain\Notification;

use App\ReactiveSystem\Part_1\Domain\Product\ProductDetails;
use Money\Money;
use Ramsey\Uuid\UuidInterface;

final class OrderConfirmationNotification
{
    /** @param ProductDetails[] $productDetails */
    public function __construct(
        private string $userFullName,
        private UuidInterface $orderId,
        private array $productDetails,
        private Money $totalAmount
    ) {}

    public function getUserFullName(): string
    {
        return $this->userFullName;
    }

    public function getOrderId(): UuidInterface
    {
        return $this->orderId;
    }

    /**
     * @return ProductDetails[]
     */
    public function getProductDetails(): array
    {
        return $this->productDetails;
    }

    public function getTotalAmount(): Money
    {
        return $this->totalAmount;
    }
}