<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Order;
use App\Domain\OrderRepository;
use App\Domain\OrderWasPlaced;
use App\Domain\ShippingService;
use Ecotone\Api\Asynchronous;
use Ecotone\Api\Header;
use Ecotone\Messaging\MessageHeaders;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\EventHandler;
use Ecotone\Api\EventBus;

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