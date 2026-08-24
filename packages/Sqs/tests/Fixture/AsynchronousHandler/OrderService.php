<?php

namespace Test\Ecotone\Sqs\Fixture\AsynchronousHandler;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;

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
