<?php

namespace Test\Ecotone\Amqp\Fixture\Order;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

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
