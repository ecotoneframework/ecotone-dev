<?php

/*
 * licence Enterprise
 */
declare(strict_types=1);

namespace Ecotone\EventSourcing\Projecting;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\Projecting\PartitionProvider;
use Enqueue\Dbal\DbalConnectionFactory;
use RuntimeException;

class AggregateIdPartitionProvider implements PartitionProvider
{
    public function __construct(
        private DbalConnectionFactory|MultiTenantConnectionFactory $connectionFactory,
        private string $aggregateType,
        private string $streamName,
        private PdoStreamTableNameProvider $tableNameProvider
    ) {
    }

    public function partitions(): iterable
    {
        $connection = $this->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Resolve table name at runtime using the provider
        $streamTable = $this->tableNameProvider->generateTableNameForStream($this->streamName);

        // Build platform-specific query
        if ($platform instanceof PostgreSQLPlatform) {
            // PostgreSQL: Use JSONB operators
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT metadata->>'_aggregate_id' AS aggregate_id
                FROM {$streamTable}
                WHERE metadata->>'_aggregate_type' = ?
                SQL, [$this->aggregateType]);
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            // MySQL/MariaDB: Use generated indexed columns for better performance
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT aggregate_id
                FROM {$streamTable}
                WHERE aggregate_type = ?
                SQL, [$this->aggregateType]);
        } else {
            throw new RuntimeException('Unsupported database platform: ' . get_class($platform));
        }

        while ($aggregateId = $query->fetchOne()) {
            yield $aggregateId;
        }
    }

    private function getConnection(): Connection
    {
        if ($this->connectionFactory instanceof MultiTenantConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->establishConnection();
    }
}
