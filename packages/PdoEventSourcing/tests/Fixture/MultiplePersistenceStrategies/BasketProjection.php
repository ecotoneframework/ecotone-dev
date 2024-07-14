<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Fixture\MultiplePersistenceStrategies;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\Attribute\Projection;
use Ecotone\EventSourcing\Attribute\ProjectionInitialization;
use Ecotone\Modelling\Attribute\EventHandler;
use Ecotone\Modelling\Attribute\QueryHandler;

#[Projection(self::NAME, fromStreams: ['basket'])]
/**
 * licence Apache-2.0
 */
final class BasketProjection
{
    public const NAME = 'basket-projection';

    public function __construct(private Connection $connection)
    {
    }

    #[QueryHandler('baskets')]
    public function getBaskets(): array
    {
        return $this->connection->fetchFirstColumn('select basket_id from baskets');
    }

    #[EventHandler(listenTo: BasketCreated::NAME)]
    public function basketCreated(BasketCreated $event): void
    {
        $this->connection->insert('baskets', ['basket_id' => $event->basketId]);
    }

    #[ProjectionInitialization]
    public function initialize(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS baskets');
        $this->connection->executeStatement('CREATE TABLE baskets (basket_id VARCHAR(36) PRIMARY KEY)');
    }
}
