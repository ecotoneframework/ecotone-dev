<?php

namespace App\OutboxPattern\Domain;

class PlaceOrder
{
    public function __construct(private string $orderId, private string $productName, private bool $shouldFail)
    {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function shouldFail(): bool
    {
        return $this->shouldFail;
    }
}