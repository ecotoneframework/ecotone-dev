<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousHandler;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private int $placedOrders = 0;

    #[Asynchronous('async')]
    #[CommandHandler('order.register', 'orderService')]
    public function order(string $orderName): void
    {
        $this->placedOrders[] = $orderName;
    }

    #[QueryHandler('getOrders')]
    public function getOrder(): int
    {
        return $this->placedOrders;
    }
}
