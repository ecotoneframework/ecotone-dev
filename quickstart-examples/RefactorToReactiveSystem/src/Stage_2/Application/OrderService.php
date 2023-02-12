<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_2\Application;

use App\ReactiveSystem\Stage_2\Domain\Clock;
use App\ReactiveSystem\Stage_2\Domain\Order\Event\OrderWasPlaced;
use App\ReactiveSystem\Stage_2\Domain\Order\Order;
use App\ReactiveSystem\Stage_2\Domain\Order\OrderRepository;
use App\ReactiveSystem\Stage_2\Domain\Product\ProductRepository;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\EventBus;
use Ramsey\Uuid\UuidInterface;

final class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository, private ProductRepository $productRepository,
        private Clock           $clock, private EventBus $eventBus
    ) {}

    #[CommandHandler]
    public function placeOrder(PlaceOrder $command): void
    {
        $productDetails = $this->productRepository->getBy($command->productId)->getProductDetails();

        /** Storing order in database */
        $order = Order::create($command->userId, $command->shippingAddress, $productDetails, $this->clock);
        $this->orderRepository->save($order);

        /** Publish event indicating that Order Was Placed */
        $this->eventBus->publish(new OrderWasPlaced($order->getOrderId()));
    }
}