<?php declare(strict_types=1);

namespace App\WorkingWithAggregateDirectly\Event;

class PriceWasChanged
{
    public function __construct(private string $productId, private float $price) {}

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getPrice(): float
    {
        return $this->price;
    }
}