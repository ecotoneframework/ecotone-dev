<?php

declare(strict_types=1);

namespace App\Domain;

interface ShippingService
{
    public function ship(Order $order): void;
}