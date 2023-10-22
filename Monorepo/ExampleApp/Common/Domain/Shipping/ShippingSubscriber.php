<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\Domain\Shipping;

use Monorepo\ExampleApp\Common\Domain\Order\Event\OrderWasPlaced;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Messaging\MessageChannelConfiguration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

final class ShippingSubscriber
{
    public function __construct(public readonly ShippingService $shippingService)
    {
    }

    #[Asynchronous('async_channel')]
    #[EventHandler(endpointId: "shipWhenOrderWasPlaced")]
    public function when(OrderWasPlaced $event, OrderRepository $orderRepository): void
    {
        $order = $orderRepository->getBy($event->orderId);

        $this->shippingService->shipOrderFor(
            $order->getUserId(), $order->getOrderId(),
            $order->getProductDetails(), $order->getShippingAddress()
        );
    }

}