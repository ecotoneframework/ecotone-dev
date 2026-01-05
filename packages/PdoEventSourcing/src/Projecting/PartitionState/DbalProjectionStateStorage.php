<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting\PartitionState;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\Database\ProjectionStateTableManager;
use Ecotone\Projecting\NoOpTransaction;
use Ecotone\Projecting\ProjectionInitializationStatus;
use Ecotone\Projecting\ProjectionPartitionState;
use Ecotone\Projecting\ProjectionStateStorage;
use Ecotone\Projecting\Transaction;
use Enqueue\Dbal\DbalConnectionFactory;
use Enqueue\Dbal\ManagerRegistryConnectionFactory;

use function json_decode;
use function json_encode;

class DbalProjectionStateStorage implements ProjectionStateStorage
{
    private const INITIALIZATION_STATUS_KEY = 'initialization_status';

    /** @var array<string, bool> Track initialization per connection */
    private array $initialized = [];

    public function __construct(
        private DbalConnectionFactory|ManagerRegistryConnectionFactory|MultiTenantConnectionFactory $connectionFactory,
        private ProjectionStateTableManager $tableManager,
    ) {
    }

    public function getTableName(): string
    {
        return $this->tableManager->getTableName();
    }

    private function getConnection(): Connection
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->createContext()->getDbalConnection();
    }

    private function getConnectionKey(): string
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            return $this->connectionFactory->currentActiveTenant();
        }

        return 'default';
    }

    private function isInitialized(): bool
    {
        return $this->initialized[$this->getConnectionKey()] ?? false;
    }

    private function markInitialized(): void
    {
        $this->initialized[$this->getConnectionKey()] = true;
    }

    public function loadPartition(string $projectionName, ?string $partitionKey = null, bool $lock = true): ?ProjectionPartitionState
    {
        $this->createSchema();

        $tableName = $this->getTableName();
        $query = <<<SQL
            SELECT last_position, user_state, metadata FROM {$tableName}
            WHERE projection_name = :projectionName AND partition_key = :partitionKey
            SQL;

        if ($lock) {
            $query .= ' FOR UPDATE';
        }

        $row = $this->getConnection()->fetchAssociative($query, [
            'projectionName' => $projectionName,
            'partitionKey' => $partitionKey ?? '',
        ]);
        if (! $row) {
            return null;
        }

        $metadata = $row['metadata'] ? json_decode($row['metadata'], true) : null;
        $status = isset($metadata[self::INITIALIZATION_STATUS_KEY]) ? ProjectionInitializationStatus::from($metadata[self::INITIALIZATION_STATUS_KEY]) : null;
        return new ProjectionPartitionState($projectionName, $partitionKey, $row['last_position'], json_decode($row['user_state'], true), $status);
    }

    public function initPartition(string $projectionName, ?string $partitionKey = null): ?ProjectionPartitionState
    {
        $this->createSchema();

        $connection = $this->getConnection();
        $tableName = $this->getTableName();

        // Try to insert the partition state, ignoring if it already exists
        $insertQuery = match (true) {
            $connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                INSERT INTO {$tableName} (projection_name, partition_key, last_position, user_state, metadata)
                VALUES (:projectionName, :partitionKey, :lastPosition, :userState, :metadata)
                ON DUPLICATE KEY UPDATE projection_name = projection_name -- no-op to ignore
                SQL,
            default => <<<SQL
                INSERT INTO {$tableName} (projection_name, partition_key, last_position, user_state, metadata)
                VALUES (:projectionName, :partitionKey, :lastPosition, :userState, :metadata)
                ON CONFLICT (projection_name, partition_key) DO NOTHING
                SQL,
        };

        $metadata = [
            self::INITIALIZATION_STATUS_KEY => $projectionState->status?->value ?? ProjectionInitializationStatus::UNINITIALIZED->value,
        ];
        $rowsAffected = $connection->executeStatement($insertQuery, [
            'projectionName' => $projectionName,
            'partitionKey' => $partitionKey ?? '',
            'lastPosition' => '',
            'userState' => json_encode(null),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ]);

        // If no rows were affected, the partition already existed
        if ($rowsAffected === 0) {
            return null;
        }

        // Return the newly created state
        return new ProjectionPartitionState($projectionName, $partitionKey, null, null, ProjectionInitializationStatus::UNINITIALIZED);
    }

    public function savePartition(ProjectionPartitionState $projectionState): void
    {
        $this->createSchema();

        $connection = $this->getConnection();
        $tableName = $this->getTableName();

        $saveStateQuery = match (true) {
            $connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                INSERT INTO {$tableName} (projection_name, partition_key, last_position, user_state, metadata)
                VALUES (:projectionName, :partitionKey, :lastPosition, :userState, :metadata)
                ON DUPLICATE KEY UPDATE last_position = :lastPosition, user_state = :userState, metadata = :metadata
                SQL,
            default => <<<SQL
                INSERT INTO {$tableName} (projection_name, partition_key, last_position, user_state, metadata)
                VALUES (:projectionName, :partitionKey, :lastPosition, :userState, :metadata)
                ON CONFLICT (projection_name, partition_key) DO UPDATE SET last_position = :lastPosition, user_state = :userState, metadata = :metadata
                SQL,
        };

        $metadata = [
            self::INITIALIZATION_STATUS_KEY => $projectionState->status?->value ?? ProjectionInitializationStatus::INITIALIZED->value,
        ];
        $connection->executeStatement($saveStateQuery, [
            'projectionName' => $projectionState->projectionName,
            'partitionKey' => $projectionState->partitionKey ?? '',
            'lastPosition' => $projectionState->lastPosition,
            'userState' => json_encode($projectionState->userState, JSON_THROW_ON_ERROR),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ]);
    }

    public function init(string $projectionName): void
    {
        $this->createSchema();
    }

    public function delete(string $projectionName): void
    {
        $this->createSchema();

        $tableName = $this->getTableName();
        $this->getConnection()->executeStatement(<<<SQL
            DELETE FROM {$tableName} WHERE projection_name = :projectionName
            SQL, [
            'projectionName' => $projectionName,
        ]);
    }

    public function createSchema(): void
    {
        if (! $this->tableManager->shouldBeInitializedAutomatically() || $this->isInitialized()) {
            return;
        }

        $connection = $this->getConnection();

        // Delegate to table manager - single source of truth for schema
        if (! $this->tableManager->isInitialized($connection)) {
            $this->tableManager->createTable($connection);
        }

        $this->markInitialized();
    }

    public function beginTransaction(): Transaction
    {
        $connection = $this->getConnection();
        if ($connection->isTransactionActive()) {
            return new NoOpTransaction();
        } else {
            $connection->beginTransaction();
            return new DbalTransaction($connection);
        }
    }
}
