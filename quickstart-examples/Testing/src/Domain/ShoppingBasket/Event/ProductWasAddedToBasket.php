<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket\Event;

use Ramsey\Uuid\UuidInterface;

final class ProductWasAddedToBasket
{
    public function __construct(
        private UuidInterface $userId,
        private UuidInterface $productId,
        private int $price
    )
    {}

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getProductId(): UuidInterface
    {
        return $this->productId;
    }

    public function getPrice(): int
    {
        return $this->price;
    }
}