<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\Domain\Order;

use App\ReactiveSystem\Part_1\Domain\Clock;
use App\ReactiveSystem\Part_1\Domain\Product\ProductDetails;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class Order
{
    /**
     * @param ProductDetails[] $productDetails
     */
    private function __construct(private UuidInterface $orderId, private UuidInterface $userId, private ShippingAddress $shippingAddress, private array $productDetails, private \DateTimeImmutable $orderAt) {}

    public static function create(UuidInterface $userId, ShippingAddress $shippingAddress, array $productsDetails, Clock $clock): self
    {
        return new self(Uuid::uuid4(), $userId, $shippingAddress, $productsDetails, $clock->getCurrentTime());
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

    /**
     * @return ProductDetails[]
     */
    public function getProductDetails(): array
    {
        return $this->productDetails;
    }

    public function getTotalPrice(): Money
    {
        $totalPrice = Money::EUR(0);

        foreach ($this->productDetails as $productDetail) {
            $totalPrice = $totalPrice->add($productDetail->productPrice);
        }

        return $totalPrice;
    }
}