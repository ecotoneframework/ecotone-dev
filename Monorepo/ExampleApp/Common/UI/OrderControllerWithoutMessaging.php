<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\UI;

use Monorepo\ExampleApp\Common\Domain\Clock;
use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\Order;
use Monorepo\ExampleApp\Common\Domain\Order\OrderRepository;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Domain\Product\ProductRepository;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Ecotone\Modelling\CommandBus;
use Monorepo\ExampleApp\Common\Infrastructure\InMemory\InMemoryOrderRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderControllerWithoutMessaging
{
    public function __construct(private AuthenticationService $authenticationService, private ProductRepository $productRepository, private InMemoryOrderRepository $orderRepository, private Clock $clock) {}

    public function placeOrder(Request $request): Response
    {
        $data = \json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $currentUserId = $this->authenticationService->getCurrentUserId();
        $orderId = Uuid::fromString($data['orderId']);
        $shippingAddress = new ShippingAddress($data['address']['street'], $data['address']['houseNumber'], $data['address']['postCode'], $data['address']['country']);
        $productId = Uuid::fromString($data['productId']);

        $order = Order::create(new PlaceOrder($orderId, $currentUserId, $shippingAddress, $productId), $this->productRepository, $this->clock);
        $this->orderRepository->save([$orderId->toString()], $order, [], null);

        return new Response();
    }
}