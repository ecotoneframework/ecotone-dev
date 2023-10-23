<?php declare(strict_types=1);

namespace Monorepo\ExampleApp\CommonEventSourcing;

class PriceChange
{
    public function __construct(private float $price, private float $priceChange) {}

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getPriceChange(): float
    {
        return $this->priceChange;
    }
}