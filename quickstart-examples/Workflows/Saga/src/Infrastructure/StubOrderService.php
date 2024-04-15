<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Infrastructure;

use App\Workflow\Saga\Application\Order\OrderService;
use Ecotone\Modelling\Attribute\Identifier;
use Money\Money;

final readonly class StubOrderService implements OrderService
{
    public function __construct(private Money $totalPrice)
    {
    }

    public function getTotalPriceFor(string $orderId): Money
    {
        return $this->totalPrice;
    }
}