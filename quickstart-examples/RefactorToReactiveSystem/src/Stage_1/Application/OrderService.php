<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\Application;

use App\ReactiveSystem\Stage_1\Domain\Clock;
use App\ReactiveSystem\Stage_1\Domain\Notification\NotificationSender;
use App\ReactiveSystem\Stage_1\Domain\Notification\OrderConfirmationNotification;
use App\ReactiveSystem\Stage_1\Domain\Order\Order;
use App\ReactiveSystem\Stage_1\Domain\Order\OrderRepository;
use App\ReactiveSystem\Stage_1\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Stage_1\Domain\Product\ProductRepository;
use App\ReactiveSystem\Stage_1\Domain\Shipping\ShippingService;
use App\ReactiveSystem\Stage_1\Domain\User\UserRepository;
use Ramsey\Uuid\UuidInterface;

final class OrderService
{
    public function __construct(
        private OrderRepository   $orderRepository, private UserRepository $userRepository,
        private ProductRepository $productRepository, private NotificationSender $notifcationSender,
        private ShippingService   $shippingService, private Clock $clock
    ) {}

    /**
     * @param UuidInterface[] $productIds
     */
    public function placeOrder(UuidInterface $userId, ShippingAddress $shippingAddress, array $productIds): void
    {
        /** Storing order in database */
        $productsDetails = array_map(fn(UuidInterface $productId) => $this->productRepository->getBy($productId)->getProductDetails(), $productIds);
        $order = Order::create($userId, $shippingAddress, $productsDetails, $this->clock);
        $this->orderRepository->save($order);

        /** Sending order confirmation notification */
        $user = $this->userRepository->getBy($order->getUserId());
        $this->notifcationSender->send(new OrderConfirmationNotification($user->getFullName(), $order->getOrderId(), $productsDetails, $order->getTotalPrice()));

        /** Calling Shipping Service over HTTP, to deliver products */
        $this->shippingService->shipOrderFor($userId, $order->getOrderId(), $productsDetails, $shippingAddress);
    }
}