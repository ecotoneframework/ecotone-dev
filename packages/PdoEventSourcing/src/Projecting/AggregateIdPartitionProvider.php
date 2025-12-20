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
use Ecotone\Projecting\PartitionProvider;
use Enqueue\Dbal\DbalConnectionFactory;
use RuntimeException;

use function sha1;

class AggregateIdPartitionProvider implements PartitionProvider
{
    private string $streamTable;
    public function __construct(
        private DbalConnectionFactory|MultiTenantConnectionFactory $connectionFactory,
        private string $aggregateType,
        private string $streamName
    ) {
        // This is the name Prooph uses to store events in the database
        $this->streamTable = '_' . sha1($this->streamName);
    }

    public function partitions(): iterable
    {
        $connection = $this->getConnection();
        $platform = $connection->getDatabasePlatform();

        // Build platform-specific query
        if ($platform instanceof PostgreSQLPlatform) {
            // PostgreSQL: Use JSONB operators
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT metadata->>'_aggregate_id' AS aggregate_id
                FROM {$this->streamTable}
                WHERE metadata->>'_aggregate_type' = ?
                SQL, [$this->aggregateType]);
        } elseif ($platform instanceof MySQLPlatform || $platform instanceof MariaDBPlatform) {
            // MySQL/MariaDB: Use generated indexed columns for better performance
            $query = $connection->executeQuery(<<<SQL
                SELECT DISTINCT aggregate_id
                FROM {$this->streamTable}
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
