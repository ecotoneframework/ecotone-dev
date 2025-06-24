<?php

declare(strict_types=1);

namespace Test\Ecotone\Tempest\Fixture\Order;

use Ecotone\Modelling\Attribute\CommandHandler;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;
use Tempest\Container\Singleton;

/**
 * licence Apache-2.0
 */
#[Singleton]
final class OrderService
{
    private static array $orders = [];
    private static array $events = [];

    #[CommandHandler]
    public function handle(PlaceOrder $command): void
    {
        $orderId = 'order_' . uniqid();
        $order = Order::place($orderId, $command->userId, $command->productIds);
        
        self::$orders[$orderId] = $order;
        self::$events[] = new OrderWasPlaced($orderId, $command->userId, $command->productIds);
    }

    #[QueryHandler]
    public function getOrder(GetOrder $query): ?Order
    {
        return self::$orders[$query->orderId] ?? null;
    }

    #[EventHandler]
    public function onOrderPlaced(OrderWasPlaced $event): void
    {
        // Handle order placed event - could send emails, update inventory, etc.
        echo "Order {$event->orderId} was placed for user {$event->userId}\n";
    }

    public static function reset(): void
    {
        self::$orders = [];
        self::$events = [];
    }

    public static function getEvents(): array
    {
        return self::$events;
    }

    public static function getOrders(): array
    {
        return self::$orders;
    }
}
