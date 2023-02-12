<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_1\UI;

use App\ReactiveSystem\Stage_1\Application\OrderService;
use App\ReactiveSystem\Stage_1\Domain\Order\ShippingAddress;
use App\ReactiveSystem\Stage_1\Infrastructure\Authentication\AuthenticationService;
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
        $productId = $data['productId'];

        $this->orderService->placeOrder($currentUserId, $shippingAddress, Uuid::fromString($productId));

        return new Response();
    }
}