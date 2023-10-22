<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Order;

final class ShippingAddress
{
    public function __construct(
        public string $street,
        public string $houseNumber,
        public string $postCode,
        public string $country
    ) {}
}