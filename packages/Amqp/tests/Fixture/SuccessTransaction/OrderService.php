<?php

namespace Test\Ecotone\Amqp\Fixture\SuccessTransaction;

use Ecotone\Api\Attribute\ServiceActivator;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private ?string $order = null;

    #[CommandHandler('order.register')]
    public function register(string $order, OrderRegisteringGateway $orderRegisteringGateway): void
    {
        $orderRegisteringGateway->place($order);
    }

    #[ServiceActivator('placeOrder', 'placeOrderEndpoint')]
    public function receive(string $order): void
    {
        $this->order = $order;
    }

    #[QueryHandler('order.getOrder')]
    public function getOrder(): ?string
    {
        $order = $this->order;
        $this->order = null;

        return $order;
    }
}
