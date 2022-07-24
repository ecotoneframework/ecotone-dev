<?php

namespace App\Conversion;

use Ramsey\Uuid\UuidInterface;

class PlaceOrder
{
    public readonly string $orderId;
    /**
     * @var UuidInterface[]
     */
    public readonly array $productIds;
}