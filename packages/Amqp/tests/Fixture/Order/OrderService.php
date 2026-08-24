<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\QueryHandler;

#[Asynchronous('orders')]
/**
 * licence Apache-2.0
 */
class OrderService
{
    /**
     * @var string[]
     */
    private $orders = [];

    #[CommandHandler('order.register', 'orderReceiver')]
    public function register(string $placeOrder): void
    {
        $this->orders[] = $placeOrder;
    }

    #[QueryHandler('order.getOrders')]
    public function getRegisteredOrders(): array
    {
        return $this->orders;
    }
}
