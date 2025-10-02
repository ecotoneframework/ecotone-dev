<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\PartitionState;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Projecting\NoOpTransaction;
use Ecotone\Projecting\ProjectionPartitionState;
use Ecotone\Projecting\ProjectionStateStorage;
use Ecotone\Projecting\Transaction;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

use function json_decode;
use function json_encode;

class DbalProjectionStateStorage implements ProjectionStateStorage
{
    private const STATE_INITIALIZED = 'initialized';

    private ?string $saveStateQuery = null;

    private Connection $connection;
    private bool $initialized = false;

    public function __construct(
        DbalConnectionFactory|ManagerRegistryConnectionFactory $connectionFactory,
        private string        $stateTable = 'ecotone_projection_state',
    ) {
        $this->connection = $connectionFactory->createContext()->getDbalConnection();
    }

    public function loadPartition(string $projectionName, ?string $partitionKey = null, bool $lock = true): ProjectionPartitionState
    {
        $this->createSchema();

        $query = <<<SQL
            SELECT last_position, user_state FROM {$this->stateTable}
            WHERE projection_name = :projectionName AND partition_key = :partitionKey
            SQL;

        if ($lock) {
            $query .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($query, [
            'projectionName' => $projectionName,
            'partitionKey' => $partitionKey ?? '',
        ]);
        if (! $row) {
            return new ProjectionPartitionState($projectionName, $partitionKey);
        }

        return new ProjectionPartitionState($projectionName, $partitionKey, $row['last_position'], json_decode($row['user_state'], true));
    }

    public function savePartition(ProjectionPartitionState $projectionState): void
    {
        $this->createSchema();

        if (! $this->saveStateQuery) {
            $this->saveStateQuery = match(true) {
                $this->connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                    INSERT INTO {$this->stateTable} (projection_name, partition_key, last_position, user_state)
                    VALUES (:projectionName, :partitionKey, :lastPosition, :userState)
                    ON DUPLICATE KEY UPDATE last_position = :lastPosition, user_state = :userState
                    SQL,
                default => <<<SQL
                    INSERT INTO {$this->stateTable} (projection_name, partition_key, last_position, user_state)
                    VALUES (:projectionName, :partitionKey, :lastPosition, :userState)
                    ON CONFLICT (projection_name, partition_key) DO UPDATE SET last_position = :lastPosition, user_state = :userState
                    SQL,
            };
        }

        $this->connection->executeStatement($this->saveStateQuery, [
            'projectionName' => $projectionState->projectionName,
            'partitionKey' => $projectionState->partitionKey ?? '',
            'lastPosition' => $projectionState->lastPosition,
            'userState' => json_encode($projectionState->userState),
        ]);
    }

    public function init(string $projectionName): void
    {
        $this->createSchema();
    }

    public function delete(string $projectionName): void
    {
        $this->createSchema();

        $this->connection->executeStatement(<<<SQL
            DELETE FROM {$this->stateTable} WHERE projection_name = :projectionName
            SQL, [
            'projectionName' => $projectionName,
        ]);
    }

    public function createSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . $this->stateTable . ' (
                projection_name VARCHAR(255) NOT NULL,
                partition_key VARCHAR(255),
                last_position TEXT NOT NULL,
                user_state JSON,
                PRIMARY KEY (projection_name, partition_key)
            )'
        );
    }

    public function beginTransaction(): Transaction
    {
        if ($this->connection->isTransactionActive()) {
            return new NoOpTransaction();
        } else {
            $this->connection->beginTransaction();
            return new DbalTransaction($this->connection);
        }
    }
}
