<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Messaging\Config\Container\Definition;

use function is_array;

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
        return $connection->createSchemaManager()->tableExists($this->tableName);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName, $this->isUsed, $this->consoleInvocationPrefix]);
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix);
    }

    public function getMissingVersionColumnInstructions(): string
    {
        return "The '{$this->tableName}' table required by the '" . self::FEATURE_NAME . "' feature has no 'version' column: it was created before Ecotone 2.0 (by ProjectionV2 in 1.x, or by an earlier 2.0 beta), "
            . 'and since 2.0 every save of a projection partition checks the version it loaded, so two runs of the same partition cannot both commit. '
            . "Ecotone never alters a table while it handles a message. Add the column: ALTER TABLE {$this->tableName} ADD COLUMN version INTEGER NOT NULL DEFAULT 0; "
            . 'It is safe to run on a populated table: every stored partition starts at version 0 and keeps its position.';
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
                version INTEGER NOT NULL DEFAULT 0,
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
                `version` INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (`projection_name`, `partition_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL;
    }
}
