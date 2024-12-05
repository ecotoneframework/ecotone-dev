<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcingV2\EventStore\Fixtures;

use Ecotone\EventSourcingV2\EventStore\Dbal\Connection;
use Ecotone\EventSourcingV2\EventStore\PersistedEvent;
use Ecotone\EventSourcingV2\EventStore\Projection\Projector;
use Ecotone\EventSourcingV2\EventStore\Projection\ProjectorWithSetup;

class PostgresEventCounterProjector implements Projector, ProjectorWithSetup
{
    public function __construct(
        private Connection $connection,
        private string $tableName = 'test_event_counter',
        private ?array $streams = null,
    ) {
        if ($streams) {
            $this->streams = array_map('strval', $streams);
        }
    }

    public function project(PersistedEvent $event): void
    {
        if ($this->streams && !in_array((string) $event->streamEventId->streamId, $this->streams, true)) {
            return;
        }
        $statement = $this->connection->prepare(<<<SQL
            INSERT INTO {$this->tableName} (event_type, counter)
            VALUES (?, 1)
            ON CONFLICT (event_type) DO
                UPDATE SET counter =  {$this->tableName}.counter + 1
                WHERE  {$this->tableName}.event_type = ?
        SQL);

        $statement->execute([$event->event->type, $event->event->type]);
    }

    /**
     * @return array<string, int>
     */
    public function getCounters(): array
    {
        $statement = $this->connection->prepare(<<<SQL
            SELECT event_type, counter
            FROM {$this->tableName}
        SQL);
        $statement->execute();

        $counters = [];
        while ($row = $statement->fetch()) {
            $counters[$row['event_type']] = (int) $row['counter'];
        }
        return $counters;
    }

    public function setUp(): void
    {
        $this->connection->prepare(<<<SQL
            CREATE TABLE {$this->tableName} (
                event_type VARCHAR(255) PRIMARY KEY,
                counter INT NOT NULL DEFAULT 0
            )
        SQL)->execute();
    }

    public function tearDown(): void
    {
        $this->connection->prepare(<<<SQL
            DROP TABLE {$this->tableName}
        SQL)->execute();
    }
}