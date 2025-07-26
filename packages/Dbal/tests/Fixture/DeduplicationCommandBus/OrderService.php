<?php

declare(strict_types=1);

namespace Test\Ecotone\Dbal\Fixture\DeduplicationCommandBus;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

/**
 * licence Apache-2.0
 */
final class OrderService
{
    private int $orderCount = 0;
    private array $processedOrders = [];

    #[CommandHandler('order.place')]
    public function placeOrder(string $orderData): void
    {
        $this->orderCount++;
        $this->processedOrders[] = $orderData;
    }

    #[CommandHandler('order.cancel')]
    public function cancelOrder(string $orderId): void
    {
        $this->orderCount++;
        $this->processedOrders[] = "cancelled-{$orderId}";
    }

    #[QueryHandler('order.getCount')]
    public function getOrderCount(): int
    {
        return $this->orderCount;
    }

    #[QueryHandler('order.getProcessedOrders')]
    public function getProcessedOrders(): array
    {
        return $this->processedOrders;
    }

    #[QueryHandler('order.reset')]
    public function reset(): void
    {
        $this->orderCount = 0;
        $this->processedOrders = [];
    }
}
