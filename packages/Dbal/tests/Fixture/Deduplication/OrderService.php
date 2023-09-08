<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Deduplicated;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Ecotone\Modelling\EventBus;

class OrderService
{
    private int $callCounter = 0;
    /** @var string[] */
    private array $orders = [];

    #[Asynchronous(ChannelConfiguration::CHANNEL_NAME)]
    #[CommandHandler('placeOrder', 'placeOrderEndpoint')]
    public function placeOrder(string $order, EventBus $eventBus): void
    {
        $this->callCounter++;
        $this->orders[] = $order;

        $eventBus->publish(new OrderPlaced($order));
    }

    #[Deduplicated('orderId1')]
    #[CommandHandler('placeOrderSynchronously1', endpointId: 'placeOrderSynchronouslyOne')]
    public function placeOrderSynchronouslyOne(string $order, EventBus $eventBus): void
    {
        $this->callCounter++;
        $this->orders[] = $order;

        $eventBus->publish(new OrderPlaced($order));
    }

    #[Deduplicated('orderId2')]
    #[CommandHandler('placeOrderSynchronously2')]
    public function placeOrderSynchronouslyTwo(string $order, EventBus $eventBus): void
    {
        $this->callCounter++;
        $this->orders[] = $order;

        $eventBus->publish(new OrderPlaced($order));
    }

    #[Deduplicated('orderId1')]
    #[CommandHandler('placeOrderSynchronously3', endpointId: 'placeOrderSynchronouslyThree')]
    public function placeOrderSynchronouslyThree(string $order, EventBus $eventBus): void
    {
        $this->callCounter++;
        $this->orders[] = $order;

        $eventBus->publish(new OrderPlaced($order));
    }

    #[QueryHandler('order.getRegistered')]
    public function getOrders(): array
    {
        return $this->orders;
    }
}
