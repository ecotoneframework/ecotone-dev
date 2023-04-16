<?php

declare(strict_types=1);

namespace App\Domain;

final class OrderWasPlaced
{
    public function __construct(
        public string $orderId,
        public string $productName
    ) {}
}