<?php

declare(strict_types=1);

namespace App\Workflow\Saga\Application\Order;

use Ecotone\Api\BusinessMethod;
use Ecotone\Api\Identifier;
use Money\Money;

interface OrderService
{
    /**
     * @link https://docs.ecotone.tech/modelling/command-handling/business-interface
     */
    #[BusinessMethod("order.getTotalPrice")]
    public function getTotalPriceFor(#[Identifier] string $orderId): Money;
}