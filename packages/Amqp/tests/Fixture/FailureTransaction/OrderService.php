<?php

namespace Test\Ecotone\Amqp\Fixture\FailureTransaction;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\CommandBus;
use InvalidArgumentException;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private $order = null;

    #[CommandHandler('order.register')]
    public function register(string $order, CommandBus $commandBus): void
    {
        $commandBus->sendWithRouting('makeOrder', $order);

        throw new InvalidArgumentException('test');
    }

    #[Asynchronous('placeOrder')]
    #[CommandHandler('makeOrder', 'placeOrderEndpoint')]
    public function placeOrder(string $order): void
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
