<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\EventSourcing\Dbal\EventStreamSchema;
use Ecotone\EventSourcing\Dbal\MariaDbEventStreamSchema;
use Ecotone\EventSourcing\Dbal\MySqlEventStreamSchema;
use Ecotone\EventSourcing\Dbal\PostgresEventStreamSchema;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class EventStreamTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'event_stream';

    /**
     * @param array<string> $tableNames
     */
    public function __construct(
        private array $tableNames,
        private bool  $isUsed,
        private bool  $shouldAutoInitialize,
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

    /**
     * @return array<string>
     */
    public function getTableNames(): array
    {
        return $this->tableNames;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        $schema = $this->schemaFor($connection);
        $statements = [];
        foreach ($this->tableNames as $tableName) {
            foreach ($schema->createTableSql($tableName) as $statement) {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    public function getDropTableSql(Connection $connection): string
    {
        $schema = $this->schemaFor($connection);

        return implode('; ', array_map(
            fn (string $tableName) => $schema->dropTableSql($tableName),
            $this->tableNames
        ));
    }

    public function createTable(Connection $connection): void
    {
        foreach ($this->getCreateTableSql($connection) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function dropTable(Connection $connection): void
    {
        $schema = $this->schemaFor($connection);
        foreach ($this->tableNames as $tableName) {
            $connection->executeStatement($schema->dropTableSql($tableName));
        }
    }

    public function isInitialized(Connection $connection): bool
    {
        $schema = $this->schemaFor($connection);
        foreach ($this->tableNames as $tableName) {
            if (! $schema->tableExists($connection, $tableName)) {
                return false;
            }
        }

        return true;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableNames, $this->isUsed, $this->shouldAutoInitialize]);
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    private function schemaFor(Connection $connection): EventStreamSchema
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => new PostgresEventStreamSchema(),
            $platform instanceof MariaDBPlatform => new MariaDbEventStreamSchema(),
            default => new MySqlEventStreamSchema(),
        };
    }
}
