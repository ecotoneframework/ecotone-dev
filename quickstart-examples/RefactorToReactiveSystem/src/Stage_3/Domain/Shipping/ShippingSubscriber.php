<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Shipping;

use App\ReactiveSystem\Stage_3\Domain\Order\Event\OrderWasPlaced;
use App\ReactiveSystem\Stage_3\Domain\Order\OrderRepository;
use App\ReactiveSystem\Stage_3\Infrastructure\Messaging\MessageChannelConfiguration;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;

final class ShippingSubscriber
{
    #[Asynchronous(MessageChannelConfiguration::ASYNCHRONOUS_CHANNEL)]
    #[EventHandler(endpointId: "shipWhenOrderWasPlaced")]
    public function whenOrderWasPlaced(OrderWasPlaced $event, OrderRepository $orderRepository, ShippingService $shippingService): void
    {
        /** Sending order confirmation notification */
        $order = $orderRepository->getBy($event->orderId);

        /** Calling Shipping Service over HTTP, to deliver products */
        $shippingService->shipOrderFor($order->getUserId(), $order->getOrderId(), $order->getProductsDetails(), $order->getShippingAddress());
    }
}