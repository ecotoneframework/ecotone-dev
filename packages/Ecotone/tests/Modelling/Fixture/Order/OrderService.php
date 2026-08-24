<?php

namespace Test\Ecotone\Modelling\Fixture\Order;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventBus;
use Ecotone\Api\EventHandler;
use Ecotone\Api\QueryHandler;

#[Asynchronous('orders')]
/**
 * licence Apache-2.0
 */
class OrderService
{
    /**
     * @var PlaceOrder[]
     */
    private $orders = [];
    /**
     * @var int[]
     */
    private $notifiedOrders = [];

    #[CommandHandler(endpointId: 'registerOrderByClass')]
    #[CommandHandler('order.register', 'registerOrderByRouting')]
    public function register(PlaceOrder $placeOrder, EventBus $eventBus): void
    {
        $this->orders[] = $placeOrder;
        $eventBus->publish(new OrderWasPlaced($placeOrder->getOrderId()), []);
    }

    #[EventHandler(endpointId: 'notifyOrderWasPlaced')]
    public function notify(OrderWasPlaced $orderWasPlaced): void
    {
        $this->notifiedOrders[] = $orderWasPlaced->getOrderId();
    }

    #[QueryHandler('order.getNotifiedOrders')]
    public function getNotifiedOrders(): array
    {
        return $this->notifiedOrders;
    }

    #[QueryHandler('order.getOrders')]
    public function getRegisteredOrders(): array
    {
        return $this->orders;
    }
}
