<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket\Event;

use Ramsey\Uuid\UuidInterface;

final class ProductWasAddedToBasket
{
    public function __construct(
        private UuidInterface $userId,
        private string        $product
    )
    {}

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    public function getProduct(): string
    {
        return $this->product;
    }
}