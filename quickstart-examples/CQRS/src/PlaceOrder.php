<?php

namespace App\CQRS;

class PlaceOrder
{
    private string $orderId;
    private string $productName;

    public function __construct(string $orderId, string $productName)
    {
        $this->orderId = $orderId;
        $this->productName = $productName;
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