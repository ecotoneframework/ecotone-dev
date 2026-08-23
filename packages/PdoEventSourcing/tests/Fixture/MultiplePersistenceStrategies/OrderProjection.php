<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\Api\Attribute\ProjectionInitialization;
use Ecotone\Api\Attribute\EventHandler;
use Ecotone\Api\Attribute\QueryHandler;

#[Projection(self::NAME, fromCategories: ['order'])]
/**
 * licence Apache-2.0
 */
final class OrderProjection
{
    public const NAME = 'order-projection';

    public function __construct(private Connection $connection)
    {
    }

    #[QueryHandler('orders')]
    public function getOrders(): array
    {
        return $this->connection->fetchFirstColumn('select order_id from orders');
    }

    #[EventHandler(listenTo: OrderCreated::NAME)]
    public function orderCreated(OrderCreated $event): void
    {
        $this->connection->insert('orders', ['order_id' => $event->orderId]);
    }

    #[ProjectionInitialization]
    public function initialize(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS orders');
        $this->connection->executeStatement('CREATE TABLE orders (order_id VARCHAR(36) PRIMARY KEY)');
    }
}
