<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Order;

use App\ReactiveSystem\Stage_3\Domain\Clock;
use App\ReactiveSystem\Stage_3\Domain\Product\ProductDetails;
use Money\Money;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class Order
{
    /**
     * @param ProductDetails[] $productsDetails
     */
    private function __construct(private UuidInterface $orderId, private UuidInterface $userId, private ShippingAddress $shippingAddress, private array $productsDetails, private \DateTimeImmutable $orderAt) {}

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
    public function getProductsDetails(): array
    {
        return $this->productsDetails;
    }

    public function getTotalPrice(): Money
    {
        $totalPrice = Money::EUR(0);

        foreach ($this->productsDetails as $productDetail) {
            $totalPrice = $totalPrice->add($productDetail->productPrice);
        }

        return $totalPrice;
    }
}