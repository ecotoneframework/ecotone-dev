<?php

declare(strict_types=1);

namespace App\Domain;

use Ecotone\Modelling\Attribute\Aggregate;
use Ecotone\Modelling\Attribute\Identifier;

#[Aggregate]
final class Order
{
    public function __construct(
        #[Identifier] public readonly string $orderId,
        public readonly string $productName
    ) {}

    public static function create(string $userId, string $productName)
    {
        return new self($userId, $productName);
    }
}