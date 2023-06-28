<?php

declare(strict_types=1);

namespace App\Domain\Product\Command;

use Money\Money;
use Ramsey\Uuid\UuidInterface;

final class ChangeProductPrice
{
    public function __construct(
        public UuidInterface $productId,
        public Money $price
    ) {}
}