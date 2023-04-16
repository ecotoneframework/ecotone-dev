<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Order;
use App\Domain\OrderRepository;
use App\Domain\OrderWasPlaced;
use App\Domain\ShippingService;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;

final class OrderService
{
    #[CommandHandler]
    public function placeOrder(PlaceOrder $placeOrder, OrderRepository $orderRepository, EventBus $eventBus, #[Header('event_message_id')] $eventMessageId): void
    {
        $order = Order::create($placeOrder->orderId, $placeOrder->productName);
        $orderRepository->save($order);

        $eventBus->publish(new OrderWasPlaced($placeOrder->orderId, $placeOrder->productName), metadata: [
            MessageHeaders::MESSAGE_ID => $eventMessageId
        ]);
    }

    #[Asynchronous("orders")]
    #[EventHandler(endpointId: 'whenOrderWasPlacedThenShip')]
    public function whenOrderWasPlaced(OrderWasPlaced $orderWasPlaced, OrderRepository $orderRepository, ShippingService $shippingService): void
    {
        $order = $orderRepository->get($orderWasPlaced->orderId);

        $shippingService->ship($order);
    }
}