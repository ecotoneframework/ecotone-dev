<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Messaging\Config\Container\Definition;

use function is_array;

/**
 * licence Enterprise
 */
final class LegacyProjectionsTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'projections_v1';

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
        if ($this->isPostgres($connection)) {
            return $this->getPostgresCreateSql();
        }

        if ($this->isMariaDb($connection)) {
            return $this->getMariaDbCreateSql();
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

    private function isPostgres(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private function isMariaDb(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof MariaDBPlatform;
    }

    private function getPostgresCreateSql(): string
    {
        $tableName = $this->tableName;

        return <<<SQL
            CREATE TABLE IF NOT EXISTS {$tableName} (
              no BIGSERIAL,
              name VARCHAR(150) NOT NULL,
              position JSONB,
              state JSONB,
              status VARCHAR(28) NOT NULL,
              locked_until CHAR(26),
              PRIMARY KEY (no),
              UNIQUE (name)
            )
            SQL;
    }

    private function getMariaDbCreateSql(): string
    {
        $tableName = $this->tableName;

        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
              `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(150) NOT NULL,
              `position` LONGTEXT,
              `state` LONGTEXT,
              `status` VARCHAR(28) NOT NULL,
              `locked_until` CHAR(26),
              CHECK (`position` IS NULL OR JSON_VALID(`position`)),
              CHECK (`state` IS NULL OR JSON_VALID(`state`)),
              PRIMARY KEY (`no`),
              UNIQUE KEY `ix_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
            SQL;
    }

    private function getMysqlCreateSql(): string
    {
        $tableName = $this->tableName;

        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
              `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
              `name` VARCHAR(150) NOT NULL,
              `position` JSON,
              `state` JSON,
              `status` VARCHAR(28) NOT NULL,
              `locked_until` CHAR(26),
              PRIMARY KEY (`no`),
              UNIQUE KEY `ix_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
            SQL;
    }
}
