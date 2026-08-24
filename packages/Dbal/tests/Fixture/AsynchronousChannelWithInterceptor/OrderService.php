<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

use Ecotone\Api\Asynchronous;
use Ecotone\Api\CommandHandler;
use Ecotone\Api\Header;
use Ecotone\Api\QueryHandler;

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
