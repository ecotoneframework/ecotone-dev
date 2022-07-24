<?php

namespace App\Conversion;

use Ramsey\Uuid\UuidInterface;

class PlaceOrder
{
    public string $orderId;
    /**
     * @var UuidInterface[]
     */
    public array $productIds;
}