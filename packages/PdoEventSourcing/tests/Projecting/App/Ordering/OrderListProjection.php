<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Projecting\App\Ordering;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventHandler;
use Ecotone\Api\FromStream;
use Ecotone\Api\ProjectionDelete;
use Ecotone\Api\ProjectionInitialization;
use Ecotone\Api\ProjectionV2;
use Ecotone\Api\QueryHandler;
use RuntimeException;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasCancelled;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasPlaced;
use Test\Ecotone\EventSourcing\Projecting\App\Ordering\Event\OrderWasShipped;

#[ProjectionV2(OrderListProjection::PROJECTION_NAME)]
#[FromStream(Order::STREAM_NAME, 'order')]
class OrderListProjection
{
    public const PROJECTION_NAME = 'order_list';

    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    #[QueryHandler('order.get')]
    public function get(string $orderId): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM order_list_projection WHERE order_id = ?', [$orderId]) ?: null;
    }

    #[EventHandler]
    public function onOrderWasPlaced(OrderWasPlaced $event): void
    {
        if ($event->fail) {
            throw new RuntimeException("Simulated failure during handling OrderWasPlaced for {$event->orderId}");
        }
        $this->connection->insert('order_list_projection', [
            'order_id' => $event->orderId,
            'product' => $event->product,
            'quantity' => $event->quantity,
            'status' => 'placed',
        ]);
    }

    #[EventHandler]
    public function onOrderWasShipped(OrderWasShipped $event): void
    {
        if ($event->fail) {
            throw new RuntimeException("Simulated failure during handling OrderWasShipped for {$event->orderId}");
        }
        $this->connection->update('order_list_projection', [
            'status' => 'shipped',
        ], [
            'order_id' => $event->orderId,
        ]);
    }

    #[EventHandler]
    public function onOrderWasCancelled(OrderWasCancelled $event): void
    {
        if ($event->fail) {
            throw new RuntimeException("Simulated failure during handling OrderWasCancelled for {$event->orderId}");
        }
        $this->connection->update('order_list_projection', [
            'status' => 'cancelled',
            'reason' => $event->reason,
        ], [
            'order_id' => $event->orderId,
        ]);
    }

    #[ProjectionInitialization]
    public function init(): void
    {
        $this->connection->executeStatement(<<<SQL
            CREATE TABLE IF NOT EXISTS order_list_projection (
                order_id VARCHAR(255) PRIMARY KEY,
                product VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                status VARCHAR(32) NOT NULL,
                reason VARCHAR(255)
            );
            SQL);
    }

    #[ProjectionDelete]
    public function delete(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS order_list_projection;');
    }
}
