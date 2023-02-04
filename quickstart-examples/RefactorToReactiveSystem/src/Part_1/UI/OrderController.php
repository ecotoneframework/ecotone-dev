<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Part_1\UI;

use App\ReactiveSystem\Part_1\Application\OrderService;
use App\ReactiveSystem\Part_1\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Part_1\Infrastructure\Authentication\AuthenticationService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderController
{
    public function __construct(private AuthenticationService $authenticationService, private OrderService $orderService) {}

    public function placeOrder(Request $request): Response
    {
        $data = \json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $currentUserId = $this->authenticationService->getCurrentUserId();
        $shippingAddress = new ShippingAddress($data['address']['street'], $data['address']['houseNumber'], $data['address']['postCode'], $data['address']['country']);
        $productIds = array_map(fn(string $productId) => Uuid::fromString($productId), $data['productIds']);

        $this->orderService->placeOrder($currentUserId, $shippingAddress, $productIds);

        return new Response();
    }
}