<?php declare(strict_types=1);

namespace App\WorkingWithAggregateDirectly\Event;

class ProductWasRegistered
{
    public function __construct(private int $productId, private float $price) {}

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getPrice(): float
    {
        return $this->price;
    }
}