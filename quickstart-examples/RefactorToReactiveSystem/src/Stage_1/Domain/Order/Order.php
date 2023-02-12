<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\Domain\Order;

use App\ReactiveSystem\Stage_1\Domain\Clock;
use App\ReactiveSystem\Stage_1\Domain\Product\ProductDetails;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class Order
{
    private function __construct(private UuidInterface $orderId, private UuidInterface $userId, private ShippingAddress $shippingAddress, private ProductDetails $productDetails, private \DateTimeImmutable $orderAt) {}

    public static function create(UuidInterface $userId, ShippingAddress $shippingAddress, ProductDetails $productDetails, Clock $clock): self
    {
        return new self(Uuid::uuid4(), $userId, $shippingAddress, $productDetails, $clock->getCurrentTime());
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

    public function getTotalPrice(): Money
    {
        return $this->productDetails->productPrice;
    }
}