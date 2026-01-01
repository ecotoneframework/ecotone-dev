<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\EventSourcing\Prooph\LazyProophEventStore;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Table manager for the event streams table.
 *
 * licence Enterprise
 */
final class EventStreamTableManager implements DbalTableManager
{
    public function __construct(
        private string $tableName = LazyProophEventStore::DEFAULT_STREAM_TABLE,
    ) {
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        if ($this->isPostgres($connection)) {
            return $this->getPostgresCreateSql();
        }

        return $this->getMysqlCreateSql();
    }

    public function getDropTableSql(Connection $connection): string
    {
        $tableName = $this->tableName;

        if ($this->isPostgres($connection)) {
            return "DROP TABLE IF EXISTS {$tableName}";
        }

        return "DROP TABLE IF EXISTS `{$tableName}`";
    }

    public function createTable(Connection $connection): void
    {
        $sql = $this->getCreateTableSql($connection);
        if (\is_array($sql)) {
            foreach ($sql as $statement) {
                $connection->executeStatement($statement);
            }
        } else {
            $connection->executeStatement($sql);
        }
    }

    public function dropTable(Connection $connection): void
    {
        $connection->executeStatement($this->getDropTableSql($connection));
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName]);
    }

    private function isPostgres(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function getPostgresCreateSql(): array
    {
        $tableName = $this->tableName;

        return [
            <<<SQL
            CREATE TABLE IF NOT EXISTS {$tableName} (
              no BIGSERIAL,
              real_stream_name VARCHAR(150) NOT NULL,
              stream_name CHAR(41) NOT NULL,
              metadata JSONB,
              category VARCHAR(150),
              PRIMARY KEY (no),
              UNIQUE (stream_name)
            )
            SQL,
            "CREATE INDEX IF NOT EXISTS ix_{$tableName}_category ON {$tableName} (category)",
        ];
    }

    private function getMysqlCreateSql(): string
    {
        $tableName = $this->tableName;

        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
              `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
              `real_stream_name` VARCHAR(150) NOT NULL,
              `stream_name` CHAR(41) NOT NULL,
              `metadata` JSON,
              `category` VARCHAR(150),
              PRIMARY KEY (`no`),
              UNIQUE KEY `ix_rsn` (`real_stream_name`),
              KEY `ix_cat` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
            SQL;
    }
}

