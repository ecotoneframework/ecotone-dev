<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_2\Application;

use App\ReactiveSystem\Part_2\Domain\Clock;
use App\ReactiveSystem\Part_2\Domain\Notification\NotificationSender;
use App\ReactiveSystem\Part_2\Domain\Notification\OrderConfirmationNotification;
use App\ReactiveSystem\Part_2\Domain\Order\Event\OrderWasPlaced;
use App\ReactiveSystem\Part_2\Domain\Order\Order;
use App\ReactiveSystem\Part_2\Domain\Order\OrderRepository;
use App\ReactiveSystem\Part_2\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Part_2\Domain\Product\ProductRepository;
use App\ReactiveSystem\Part_2\Domain\Shipping\ShippingService;
use App\ReactiveSystem\Part_2\Domain\User\UserRepository;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\EventBus;
use Ramsey\Uuid\UuidInterface;

final class OrderService
{
    public function __construct(
        private OrderRepository   $orderRepository, private ProductRepository $productRepository,
        private Clock $clock, private EventBus $eventBus
    ) {}

    #[CommandHandler]
    public function placeOrder(PlaceOrder $command): void
    {
        /** Storing order in database */
        $productsDetails = array_map(fn(UuidInterface $productId) => $this->productRepository->getBy($productId)->getProductDetails(), $command->productIds);
        $order = Order::create($command->userId, $command->shippingAddress, $productsDetails, $this->clock);
        $this->orderRepository->save($order);

        /** Publish event inditicating that Order Was Placed */
        $this->eventBus->publish(new OrderWasPlaced($order->getOrderId(), $order->getUserId()));
    }
}