<?php

declare(strict_types=1);

namespace Monorepo\ExampleApp\Common\UI;

use Monorepo\ExampleApp\Common\Domain\Order\Command\PlaceOrder;
use Monorepo\ExampleApp\Common\Domain\Order\ShippingAddress;
use Monorepo\ExampleApp\Common\Infrastructure\Authentication\AuthenticationService;
use Ecotone\Modelling\CommandBus;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderController
{
    public function __construct(private AuthenticationService $authenticationService, private CommandBus $commandBus) {}

    public function placeOrder(Request $request): Response
    {
        $data = \json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $currentUserId = $this->authenticationService->getCurrentUserId();
        $orderId = Uuid::fromString($data['orderId']);
        $shippingAddress = new ShippingAddress($data['address']['street'], $data['address']['houseNumber'], $data['address']['postCode'], $data['address']['country']);
        $productId = Uuid::fromString($data['productId']);

        $this->commandBus->send(new PlaceOrder($orderId, $currentUserId, $shippingAddress, $productId), metadata: [
            'orderId' => $orderId->toString()
        ]);

        return new Response();
    }
}