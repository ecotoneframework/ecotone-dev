<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Consumer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Dbal\DbalReconnectableConnectionFactory;
use Ecotone\Enqueue\ReconnectableConnectionFactory;
use Ecotone\Messaging\Consumer\ConsumerPositionTracker;
use Enqueue\Dbal\DbalContext;

/**
 * DBAL-based position tracker for persistent consumer offset storage
 * licence Apache-2.0
 */
class DbalConsumerPositionTracker implements ConsumerPositionTracker
{
    private ?string $upsertQuery = null;

    public function __construct(
        private ReconnectableConnectionFactory $connectionFactory,
        private string $tableName = 'ecotone_consumer_position'
    ) {
    }

    public function loadPosition(string $consumerId): ?string
    {
        $this->createSchemaIfNeeded();

        $connection = $this->getConnection();
        $row = $connection->fetchAssociative(
            "SELECT position FROM {$this->tableName} WHERE consumer_id = :consumerId",
            ['consumerId' => $consumerId]
        );

        return $row ? $row['position'] : null;
    }

    public function savePosition(string $consumerId, string $position): void
    {
        $this->createSchemaIfNeeded();

        $connection = $this->getConnection();

        if (!$this->upsertQuery) {
            $this->upsertQuery = match(true) {
                $connection->getDatabasePlatform() instanceof MySQLPlatform => <<<SQL
                    INSERT INTO {$this->tableName} (consumer_id, position, updated_at)
                    VALUES (:consumerId, :position, NOW())
                    ON DUPLICATE KEY UPDATE position = :position, updated_at = NOW()
                    SQL,
                default => <<<SQL
                    INSERT INTO {$this->tableName} (consumer_id, position, updated_at)
                    VALUES (:consumerId, :position, NOW())
                    ON CONFLICT (consumer_id) DO UPDATE SET position = :position, updated_at = NOW()
                    SQL,
            };
        }

        $connection->executeStatement($this->upsertQuery, [
            'consumerId' => $consumerId,
            'position' => $position,
        ]);
    }

    public function deletePosition(string $consumerId): void
    {
        $this->createSchemaIfNeeded();

        $connection = $this->getConnection();
        $connection->executeStatement(
            "DELETE FROM {$this->tableName} WHERE consumer_id = :consumerId",
            ['consumerId' => $consumerId]
        );
    }

    private function createSchemaIfNeeded(): void
    {
        $connection = $this->getConnection();

        // Check if table exists using schema manager to avoid rollback issues
        $schemaManager = $connection->createSchemaManager();
        if ($schemaManager->tablesExist([$this->tableName])) {
            return;
        }

        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS {$this->tableName} (
                consumer_id VARCHAR(255) PRIMARY KEY,
                position TEXT NOT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );
    }

    private function getConnection(): Connection
    {
        /** @var DbalContext $context */
        $context = $this->connectionFactory->createContext();

        return $context->getDbalConnection();
    }
}

