<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Messaging\Attribute\Parameter\Header;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
class OrderService
{
    private array $orders = [];

    #[Asynchronous('orders')]
    #[CommandHandler('order.register', 'orderRegister')]
    public function register(string $order, #[Header(AddMetadataInterceptor::SAFE_ORDER)] bool $safeOrder): void
    {
        if (! $safeOrder) {
            return;
        }

        $this->orders[] = $order;
    }

    #[QueryHandler('order.getRegistered')]
    public function getRegistered(): array
    {
        return $this->orders;
    }
}
