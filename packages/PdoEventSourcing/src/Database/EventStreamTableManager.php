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
final class EventStreamTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'event_streams';

    public function __construct(
        private string $tableName,
        private bool   $isUsed,
        private bool   $shouldAutoInitialize,
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
        return new Definition(self::class, [$this->tableName, $this->isUsed, $this->shouldAutoInitialize]);
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

    private function getMariaDbCreateSql(): string
    {
        $tableName = $this->tableName;

        return <<<SQL
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `real_stream_name` VARCHAR(150) NOT NULL,
                `stream_name` CHAR(41) NOT NULL,
                `metadata` LONGTEXT NOT NULL,
                `category` VARCHAR(150),
                CHECK (`metadata` IS NOT NULL OR JSON_VALID(`metadata`)),
                PRIMARY KEY (`no`),
                UNIQUE KEY `ix_rsn` (`real_stream_name`),
                KEY `ix_cat` (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin
            SQL;
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
