<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Domain\Order;

use App\ReactiveSystem\Stage_2\Domain\Clock;
use App\ReactiveSystem\Stage_2\Domain\Product\ProductDetails;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class Order
{
    private function __construct(private UuidInterface $orderId, private UuidInterface $userId, private ShippingAddress $shippingAddress, private ProductDetails $productDetails, private \DateTimeImmutable $orderAt) {}

    public static function create(UuidInterface $orderId, UuidInterface $userId, ShippingAddress $shippingAddress, ProductDetails $productDetails, Clock $clock): self
    {
        return new self($orderId, $userId, $shippingAddress, $productDetails, $clock->getCurrentTime());
    }

    public function getOrderId(): UuidInterface
    {
        return $this->orderId;
    }

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getShippingAddress(): ShippingAddress
    {
        return $this->shippingAddress;
    }

    public function getProductDetails(): ProductDetails
    {
        return $this->productDetails;
    }

    public function getTotalPrice(): Money
    {
        return $this->productDetails->productPrice;
    }
}