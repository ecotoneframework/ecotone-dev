<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\Application;

use App\ReactiveSystem\Stage_3\Domain\Clock;
use App\ReactiveSystem\Stage_3\Domain\Order\Event\OrderWasPlaced;
use App\ReactiveSystem\Stage_3\Domain\Order\Order;
use App\ReactiveSystem\Stage_3\Domain\Order\OrderRepository;
use App\ReactiveSystem\Stage_3\Domain\Product\ProductRepository;
use App\ReactiveSystem\Stage_3\Infrastructure\Authentication\AuthenticationService;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\EventBus;
use Ramsey\Uuid\UuidInterface;

final class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository, private ProductRepository $productRepository,
        private Clock           $clock, private EventBus $eventBus
    )
    {
    }

    #[CommandHandler("order.place")]
    public function placeOrder(PlaceOrder $command, #[Header(AuthenticationService::EXECUTOR_ID_HEADER)] UuidInterface $userId): void
    {
        /** Storing order in database */
        $productsDetails = array_map(fn(UuidInterface $productId) => $this->productRepository->getBy($productId)->getProductDetails(), $command->productIds);
        $order = Order::create($userId, $command->address, $productsDetails, $this->clock);
        $this->orderRepository->save($order);

        /** Publish event inditicating that Order Was Placed */
        $this->eventBus->publish(new OrderWasPlaced($order->getOrderId()));
    }
}