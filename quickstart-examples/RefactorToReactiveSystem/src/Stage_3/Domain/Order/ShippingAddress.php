<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Order;

final class ShippingAddress
{
    public function __construct(
        public readonly string $street,
        public readonly string $houseNumber,
        public readonly string $postCode,
        public readonly string $country
    ) {}
}