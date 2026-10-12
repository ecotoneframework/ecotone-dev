<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Domain\Shipping;

use App\ReactiveSystem\Stage_3\Domain\Order\Event\OrderWasPlaced;
use App\ReactiveSystem\Stage_3\Domain\Order\OrderRepository;
use App\ReactiveSystem\Stage_3\Infrastructure\Messaging\MessageChannelConfiguration;
use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\EventHandler;

final class ShippingSubscriber
{
    public function __construct(public readonly ShippingService $shippingService)
    {
    }

    #[Asynchronous(MessageChannelConfiguration::ASYNCHRONOUS_CHANNEL)]
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