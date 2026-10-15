<?php

namespace Test\Ecotone\Dbal\Fixture\AsynchronousChannelWithInterceptor;

use Ecotone\Api\Attribute\Asynchronous;
use Ecotone\Api\Attribute\CommandHandler;
use Ecotone\Api\Attribute\Header;
use Ecotone\Api\Attribute\QueryHandler;

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
