<?php

declare(strict_types=1);

namespace App\Application;

final class PlaceOrder
{
    public function __construct(
        public string $orderId,
        public string $productName
    ) {}
}