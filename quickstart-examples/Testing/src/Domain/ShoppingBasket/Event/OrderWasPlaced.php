<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket\Event;

use Ramsey\Uuid\UuidInterface;

final class OrderWasPlaced
{
    /**
     * @param string[] $products
     */
    public function __construct(
        private UuidInterface $userId,
        private array $products
    )
    {}

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    /**
     * @return string[]
     */
    public function getProducts(): array
    {
        return $this->products;
    }
}