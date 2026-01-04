<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Messaging\Config\Container\Definition;

use function is_array;

/**
 * Table manager for the ProjectionV2 state table.
 *
 * licence Enterprise
 */
final class ProjectionStateTableManager implements DbalTableManager
{
    public const DEFAULT_TABLE_NAME = 'ecotone_projection_state';
    public const FEATURE_NAME = 'projection_state';

    public function __construct(
        private string $tableName,
        private bool $isActive,
        private bool $shouldAutoInitialize,
    ) {
    }

    public function getFeatureName(): string
    {
        return self::FEATURE_NAME;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        if ($connection->getDatabasePlatform() instanceof MySQLPlatform) {
            return $this->getMysqlCreateSql();
        }

        return $this->getPostgresCreateSql();
    }

    public function getDropTableSql(Connection $connection): string
    {
        return "DROP TABLE IF EXISTS {$this->tableName}";
    }

    public function createTable(Connection $connection): void
    {
        if ($this->isInitialized($connection)) {
            return;
        }

        $sql = $this->getCreateTableSql($connection);
        if (is_array($sql)) {
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

    public function isInitialized(Connection $connection): bool
    {
        return SchemaManagerCompatibility::tableExists($connection, $this->tableName);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName, $this->isActive, $this->shouldAutoInitialize]);
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    private function getPostgresCreateSql(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS {$this->tableName} (
                projection_name VARCHAR(255) NOT NULL,
                partition_key VARCHAR(255) NOT NULL DEFAULT '',
                last_position TEXT NOT NULL,
                metadata JSON NOT NULL,
                user_state JSON,
                PRIMARY KEY (projection_name, partition_key)
            )
            SQL;
    }

    private function getMysqlCreateSql(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$this->tableName}` (
                `projection_name` VARCHAR(255) NOT NULL,
                `partition_key` VARCHAR(255) NOT NULL DEFAULT '',
                `last_position` TEXT NOT NULL,
                `metadata` JSON NOT NULL,
                `user_state` JSON,
                PRIMARY KEY (`projection_name`, `partition_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
    }
}
