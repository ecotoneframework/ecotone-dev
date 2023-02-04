<?php

class OrderController
{
    public function __construct(private AuthenticationService $authenticationService, private OrderService $orderService) {}

    #[POST("/order")]
    public function placeOrder(Request $request): Response
    {
        $currentUserId = $this->authenticationService->getCurrentUserId();
        $shippingAddress = new ShippingAddress($request->get('street'), $request->get('houseNumber'), $request->get('postCode'), $request->get('country'));
        $productIds = array_map(fn(string $productId) => Uuid::fromString($productId), $request->get('productIds'));

        $this->orderService->placeOrder($currentUserId, $shippingAddress, $productIds);

        return new Response(200);
    }
}

class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository, private UserRepository $userRepository,
        private AuthenticationService $authenticationService, private ProductRepository $productRepository,
        private EmailSender $emailSender, private HttpClient $httpClient, private Clock $clock
    )
    {
    }

    /**
     * @param Uuid[] $productIds
     */
    #[CommandHandler]
    public function placeOrder(Uuid $userId, ShippingAddress $shippingAddress, array $productIds): void
    {
        /** Storing order in database */
        $productsDetails = array_map(fn(Uuid $productId) => $this->productRepository->getById($productId)->getDetails(), $productIds);
        $order = Order::create($userId, $shippingAddress, $productsDetails, $this->clock);
        $this->orderRepository->save($order);

        /** Sending order confirmation notification */
        $user = $this->userRepository->findBy($order->getUserId());
        $this->emailSender->send(new OrderConfirmation($user->getFullName(), $order->getOrderId(), $productsDetails, $order->totalPrice()));

        /** Calling Shipping Service, to deliver products */
        $this->httpClient->request('POST', 'https://ecotone.tech', [
            'fullName' => $user->getFullName(),
            'orderId' => $order->getOrderId(),
            'products' => $productsDetails,
            'address' => $shippingAddress
        ]);
    }
}

class Order
{
    /** @param ProductDetails[] $productsDetails */
    private function __construct(
        private Uuid $orderId, private Uuid $userId, private ShippingAddress $shippingAddress,
        private array $productsDetails, private \DateTimeImmutable $placedAt
    ){}

    public static function create(Uuid $userId, ShippingAddress $shippingAddress, array $productsDetails, Clock $clock): self
    {
        return new self(Uuid::uuid4(), $userId, $shippingAddress, $productsDetails, $clock->getCurrentTime());
    }

    public function getOrderId(): Uuid
    {
        return $this->orderId;
    }
}

class PlaceOrder
{
    /**
     * @param Uuid[] $productIds
     */
    public function __contruct(
        public readonly Uuid $userId,
        public readonly ShippingAddress $shippingAddress,
        public readonly array $productIds
    ) {}
}