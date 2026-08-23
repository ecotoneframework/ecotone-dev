<?php

namespace Test\Ecotone\Dbal\Fixture\Deduplication;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\Deduplicated;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\Api\Gateway\EventBus;

/**
 * licence Apache-2.0
 */
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
