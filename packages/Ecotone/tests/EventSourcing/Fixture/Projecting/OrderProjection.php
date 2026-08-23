<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace EventSourcing\Fixture\Projecting;

use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\ProjectionDelete;
use Ecotone\Api\Attribute\ProjectionInitialization;
use Ecotone\Api\Attribute\ProjectionReset;
use Ecotone\Api\Attribute\QueryHandler;
use Ecotone\EventSourcing\Attribute\Projection;

#[Projection('order_projection')]
class OrderProjection
{
    private bool $initialized = false;
    private array $orders = [];

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->initialized = true;
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->initialized = false;
        $this->orders = [];
    }

    #[ProjectionReset]
    public function reset(): void
    {
        $this->orders = [];
    }

    #[QueryHandler]
    public function getOrders(): array
    {
        return $this->orders;
    }

    #[EventHandler]
    public function onOrderCreated(OrderCreated $order): void
    {
        $this->orders[$order->orderId] = $order;
    }

    #[EventHandler]
    public function onOrderCanceled(OrderCanceled $order): void
    {
        unset($this->orders[$order->orderId]);
    }
}
