<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * Table manager for the dead letter table.
 *
 * licence Apache-2.0
 */
class DeadLetterTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'dead_letter';

    public function __construct(
        private string $tableName,
        private bool $isUsed,
        private bool $shouldAutoInitialize,
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
        $table = $this->buildTableSchema();

        return $connection->getDatabasePlatform()->getCreateTableSQL($table);
    }

    public function getDropTableSql(Connection $connection): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->tableName;
    }

    public function createTable(Connection $connection): void
    {
        if (self::isInitialized($connection)) {
            return;
        }

        SchemaManagerCompatibility::getSchemaManager($connection)->createTable($this->buildTableSchema());
    }

    public function dropTable(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        if (! $schemaManager->tablesExist([$this->tableName])) {
            return;
        }

        $schemaManager->dropTable($this->tableName);
    }

    public function isInitialized(Connection $connection): bool
    {
        return SchemaManagerCompatibility::tableExists($connection, $this->tableName);
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [$this->tableName, $this->isUsed, $this->shouldAutoInitialize]
        );
    }

    public function buildTableSchema(): Table
    {
        $table = new Table($this->tableName);

        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('failed_at', Types::DATETIME_MUTABLE);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);

        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['failed_at']);
        return $table;
    }
}
