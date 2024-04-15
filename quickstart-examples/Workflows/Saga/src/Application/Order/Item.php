<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Order;

use Money\Money;
use Webmozart\Assert\Assert;

final readonly class Item
{
    public function __construct(
        public string $name,
        public Money  $pricePerItem,
    ) {
        Assert::true($pricePerItem->isPositive(), "Price per item must be greater than 0");
    }
}