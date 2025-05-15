<?php
/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Projecting\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Projecting\ProjectionState;
use Ecotone\Projecting\ProjectionStateStorage;
use Enqueue\Dbal\DbalConnectionFactory;

class DbalProjectionStateStorage implements ProjectionStateStorage
{
    private ?string $saveStateQuery = null;
    private Connection $connection;
    private bool $initialized = false;

    public function __construct(DbalConnectionFactory $connectionFactory, private string $tableName = 'ecotone_projection_state')
    {
        $this->connection = $connectionFactory->createContext()->getDbalConnection();
    }

    public function getState(string $projectionName, ?string $partitionKey = null, bool $lock = true): ProjectionState
    {
        $this->createSchema();

        $query = <<<SQL
            SELECT last_position FROM {$this->tableName}
            WHERE projection_name = :projectionName AND partition_key = :partitionKey
            SQL;

        if ($lock) {
            $query .= ' FOR UPDATE';
        }

        $lastPosition = $this->connection->fetchOne($query, [
            'projectionName' => $projectionName,
            'partitionKey' => $partitionKey,
        ]);

        return new ProjectionState($projectionName, $partitionKey, $lastPosition ?: null);
    }

    public function saveState(ProjectionState $projectionState): void
    {
        $this->createSchema();

        if (!$this->saveStateQuery) {
            $this->saveStateQuery = match(true) {
                $this->connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                    INSERT INTO {$this->tableName} (projection_name, partition_key, last_position)
                    VALUES (:projectionName, :partitionKey, :lastPosition)
                    ON DUPLICATE KEY UPDATE last_position = :lastPosition
                    SQL,
                default => <<<SQL
                    INSERT INTO {$this->tableName} (projection_name, partition_key, last_position)
                    VALUES (:projectionName, :partitionKey, :lastPosition)
                    ON CONFLICT (projection_name, partition_key) DO UPDATE SET last_position = :lastPosition
                    SQL,
            };
        }

        $this->connection->executeStatement($this->saveStateQuery, [
            'projectionName' => $projectionState->projectionName,
            'partitionKey' => $projectionState->partitionKey,
            'lastPosition' => $projectionState->lastPosition,
        ]);
    }

    public function deleteState(string $projectionName): void
    {
        $this->createSchema();

        $this->connection->executeStatement(
            'DELETE FROM ' . $this->tableName . ' WHERE projection_name = :projectionName',
            ['projectionName' => $projectionName]
        );
    }

    public function createSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . $this->tableName . ' (
                projection_name VARCHAR(255) NOT NULL,
                partition_key VARCHAR(255),
                last_position TEXT NOT NULL,
                PRIMARY KEY (projection_name, partition_key)
            )'
        );
    }
}