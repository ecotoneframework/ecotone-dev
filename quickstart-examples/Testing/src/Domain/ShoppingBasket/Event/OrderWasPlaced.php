<?php

declare(strict_types=1);

namespace App\Testing\Domain\ShoppingBasket\Event;

use Ramsey\Uuid\UuidInterface;

final class OrderWasPlaced
{
    /**
     * @var UuidInterface[]
     */
    private array $productIds;

    /**
     * @var UuidInterface[] $productIds
     */
    public function __construct(
        private UuidInterface $userId,
        array $productIds
    )
    {
        $this->productIds = $productIds;
    }

    public function getUserId(): UuidInterface
    {
        return $this->userId;
    }

    /**
     * @return UuidInterface[]
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }
}