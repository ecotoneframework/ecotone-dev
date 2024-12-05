<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\Fixtures;

use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\ProjectorWithSetup;

class PostgresTableProjector implements ProjectorWithSetup
{
    public function __construct(
        private Connection $connection,
        private string $tableName = 'test_projector',
    ) {
    }

    public function project(PersistedEvent $event): void
    {
        $this->connection->prepare(<<<SQL
            INSERT INTO {$this->tableName} (event_id)
            VALUES (?)
            SQL)->execute([$event->logEventId->sequenceNumber]);
    }

    /**
     * @return array<int>
     */
    public function getState(): array
    {
        $statement = $this->connection->prepare(<<<SQL
            SELECT event_id
            FROM {$this->tableName}
            ORDER BY event_id
        SQL);
        $statement->execute();

        $rows = [];
        while ($row = $statement->fetch()) {
            $rows[] = (int) $row['event_id'];
        }
        return $rows;
    }

    public function setUp(): void
    {
        $this->connection->prepare(<<<SQL
            CREATE TABLE {$this->tableName} (
                event_id BIGINT PRIMARY KEY
            )
            SQL)->execute();

    }

    public function tearDown(): void
    {
        $this->connection->prepare("DROP TABLE {$this->tableName}")->execute();
    }
}