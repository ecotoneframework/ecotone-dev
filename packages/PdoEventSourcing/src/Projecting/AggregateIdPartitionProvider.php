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
use Ecotone\Dbal\AlreadyConnectedDbalConnectionFactory;
use Ecotone\Dbal\MultiTenant\MultiTenantConnectionFactory;
use Ecotone\EventSourcing\PdoStreamTableNameProvider;
use Ecotone\Projecting\PartitionProvider;
use Ecotone\Projecting\StreamFilter;
use Enqueue\Dbal\DbalConnectionFactory;

use function in_array;

use RuntimeException;

class AggregateIdPartitionProvider implements PartitionProvider
{
    /**
     * @param array<string> $partitionedProjections List of projection names this provider handles
     */
    public function __construct(
        private DbalConnectionFactory|MultiTenantConnectionFactory|AlreadyConnectedDbalConnectionFactory $connectionFactory,
        private PdoStreamTableNameProvider $tableNameProvider,
        private array $partitionedProjections = [],
    ) {
    }

    public function canHandle(string $projectionName): bool
    {
        return in_array($projectionName, $this->partitionedProjections, true);
    }

    public function count(StreamFilter $filter): int
    {
        $connection = $this->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Resolve table name at runtime using the provider
        $streamTable = $this->tableNameProvider->generateTableNameForStream($filter->streamName);

        // Build platform-specific count query
        if ($platform instanceof PostgreSQLPlatform) {
            // PostgreSQL: Use JSONB operators
            $result = $connection->executeQuery(<<<SQL
                SELECT COUNT(DISTINCT metadata->>'_aggregate_id')
                FROM {$streamTable}
                WHERE metadata->>'_aggregate_type' = ?
                SQL, [$filter->aggregateType]);
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            // MySQL/MariaDB: Use generated indexed columns for better performance
            $result = $connection->executeQuery(<<<SQL
                SELECT COUNT(DISTINCT aggregate_id)
                FROM {$streamTable}
                WHERE aggregate_type = ?
                SQL, [$filter->aggregateType]);
        } else {
            throw new RuntimeException('Unsupported database platform: ' . get_class($platform));
        }

        return (int) $result->fetchOne();
    }

    public function partitions(StreamFilter $filter, ?int $limit = null, int $offset = 0): iterable
    {
        $connection = $this->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Resolve table name at runtime using the provider
        $streamTable = $this->tableNameProvider->generateTableNameForStream($filter->streamName);

        // Build pagination clause
        $limitClause = '';
        if ($limit !== null) {
            $limitClause = " LIMIT {$limit}";
        }
        $offsetClause = $offset > 0 ? " OFFSET {$offset}" : '';

        // Build platform-specific query
        if ($platform instanceof PostgreSQLPlatform) {
            // PostgreSQL: Use JSONB operators
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT metadata->>'_aggregate_id' AS aggregate_id
                FROM {$streamTable}
                WHERE metadata->>'_aggregate_type' = ?
                ORDER BY aggregate_id
                {$limitClause}{$offsetClause}
                SQL, [$filter->aggregateType]);
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            // MySQL/MariaDB: Use generated indexed columns for better performance
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT aggregate_id
                FROM {$streamTable}
                WHERE aggregate_type = ?
                ORDER BY aggregate_id
                {$limitClause}{$offsetClause}
                SQL, [$filter->aggregateType]);
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

        if ($this->connectionFactory instanceof AlreadyConnectedDbalConnectionFactory) {
            return $this->connectionFactory->getConnection();
        }

        return $this->connectionFactory->establishConnection();
    }
}
