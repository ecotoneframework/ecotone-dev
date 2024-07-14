<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MetadataPropagationWithAsyncProjection;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Messaging\Attribute\Asynchronous;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Asynchronous(channelName: self::CHANNEL)]
#[Projection(name: self::NAME, fromStreams: [Order::class])]
/**
 * licence Apache-2.0
 */
final class OrderProjection
{
    public const CHANNEL = 'projection_channel';
    public const NAME = 'order_projection';
    public const TABLE = 'foo_orders';

    public function __construct(private Connection $connection)
    {
    }

    #[ProjectionInitialization]
    public function initialization(): void
    {
        $this->connection->executeStatement(sprintf('CREATE TABLE IF NOT EXISTS %s (id INT PRIMARY KEY, foo INT DEFAULT 0)', self::TABLE));
    }

    #[EventHandler(listenTo: 'order.created', endpointId: 'foo_orders.order_created')]
    public function whenOrderCreated(OrderCreated $event, array $metadata): void
    {
        $data = ['id' => $event->id];
        if (array_key_exists('foo', $metadata)) {
            $data['foo'] = 1;
        }

        $this->connection->insert(self::TABLE, $data);
    }

    #[EventHandler(listenTo: 'order.product_added', endpointId: 'foo_orders.product_added')]
    public function whenProductAddedToOrder(ProductAddedToOrder $event, array $metadata): void
    {
        if (array_key_exists('foo', $metadata)) {
            $this->connection->executeStatement(sprintf('UPDATE %s SET foo = foo + 1 WHERE id = ?', self::TABLE), [$event->id]);
        }
    }

    #[QueryHandler(routingKey: 'foo_orders.count')]
    public function fooOrdersCount(): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT SUM(foo) FROM %s', self::TABLE));
    }
}
