<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\Database\DdlOutsideActiveTransaction;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Table manager for the Projection state table.
 *
 * licence Apache-2.0
 */
final class ProjectionStateTableManager implements DbalTableManager
{
    public const DEFAULT_TABLE_NAME = 'ecotone_projection_state';
    public const FEATURE_NAME = 'projection_state';

    public function __construct(
        private string $tableName,
        private bool   $isUsed,
        private bool   $shouldAutoInitialize,
        private ?string $consoleInvocationPrefix = null,
    ) {
    }

    public function getFeatureName(): string
    {
        return self::FEATURE_NAME;
    }

    public function isUsed(): bool
    {
        return $this->isUsed;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
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

        DdlOutsideActiveTransaction::execute($connection, $this->getCreateTableSql($connection));
    }

    public function dropTable(Connection $connection): void
    {
        DdlOutsideActiveTransaction::execute($connection, $this->getDropTableSql($connection));
    }

    public function isInitialized(Connection $connection): bool
    {
        return $connection->createSchemaManager()->tableExists($this->tableName);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName, $this->isUsed, $this->shouldAutoInitialize, $this->consoleInvocationPrefix]);
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix);
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
