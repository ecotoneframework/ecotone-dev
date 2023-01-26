<?php

use Ramsey\Uuid\UuidInterface;

class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository, private UserRepository $userRepository,
        private ProductRepository $productRepository, private EmailSender $emailSender,
        private HttpClient $httpClient, private Clock $clock
    )
    {
    }

    public function placeOrder(UuidInterface $orderId, UuidInterface $userId, array $productIds): void
    {
        /** Storing order in database */
        $productsDetails = array_map(fn(UuidInterface $productId) => $this->productRepository->getById($productId)->getDetails(), $productIds);
        $order = Order::create($orderId, $userId, $productsDetails, $this->clock);
        $this->orderRepository->save($order);

        /** Sending order confirmation notification */
        $user = $this->userRepository->findBy($order->getUserId());
        $this->emailSender->send(new OrderConfirmation($user->getFullName(), $orderId, $productsDetails, $order->totalPrice()));

        /** Calling Shipping Service, to deliver products */
        $this->httpClient->request('POST', 'https://ecotone.tech', [
            'products' => $productsDetails,
            'address' => $user->getDeliveryAddress()
        ]);
    }
}