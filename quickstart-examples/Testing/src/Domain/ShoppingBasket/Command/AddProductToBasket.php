<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket\Command;

use Ramsey\Uuid\UuidInterface;

final class AddProductToBasket
{
    public function __construct(
        private UuidInterface $userId,
        private UuidInterface $productId
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
}