<?php

namespace App\OutboxPattern\Domain;

final class OrderWasPlaced
{
    public function __construct(private string $orderId, private string $productName)
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
}