<?php

declare(strict_types=1);

namespace App\ReactiveSystem\Stage_3\UI;

use Ecotone\Modelling\CommandBus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrderController
{
    public function __construct(private CommandBus $commandBus) {}

    public function placeOrder(Request $request): Response
    {
        $this->commandBus->sendWithRouting("order.place", $request->getContent(), "application/json");

        return new Response();
    }
}