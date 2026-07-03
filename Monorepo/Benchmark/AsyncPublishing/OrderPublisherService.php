<?php

declare(strict_types=1);

namespace Monorepo\Benchmark\AsyncPublishing;

use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\EventBus;

final class OrderPublisherService
{
    #[CommandHandler('benchmark.publishOrders')]
    public function publishOrders(int $amountOfOrders, EventBus $eventBus): void
    {
        for ($orderNumber = 0; $orderNumber < $amountOfOrders; $orderNumber++) {
            $eventBus->publish(new BenchmarkOrderPlaced((string) $orderNumber));
        }
    }

    #[Asynchronous('benchmark_orders')]
    #[EventHandler(endpointId: 'benchmark_order_consumer')]
    public function consume(BenchmarkOrderPlaced $event): void
    {
    }
}
