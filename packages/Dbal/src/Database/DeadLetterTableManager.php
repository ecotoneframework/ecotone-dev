<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\ExtensionObject\ModulePackageList;
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
        private ?string $consoleInvocationPrefix = null,
        private ?string $connectionReferenceName = null,
    ) {
    }

    public function getFeatureName(): string
    {
        return self::FEATURE_NAME;
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
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

        return DbalIdempotentDdl::withIfNotExists($connection->getDatabasePlatform()->getCreateTableSQL($table));
    }

    public function getDropTableSql(Connection $connection): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->tableName;
    }

    public function createTable(Connection $connection): void
    {
        if ($this->isInitialized($connection)) {
            return;
        }

        try {
            $connection->createSchemaManager()->createTable($this->buildTableSchema());
        } catch (TableExistsException) {
        }
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix, $this->connectionReferenceName);
    }

    public function getMissingPartitionColumnsInstructions(): string
    {
        $table = $this->tableName;
        $index = $this->partitionIndexName();

        return "The '{$table}' table has no 'partition_tracking_name', 'partition_key' and 'partition_place' columns, which holding partition keys in the dead letter (#[PartitionDeadLetter], PartitionDeadLetterConfiguration) writes and reads. The table was created before these columns were part of it. Ecotone never alters a table while it handles a message.\n\n"
            . "Add the columns with the statement for your database:\n"
            . "  PostgreSQL: ALTER TABLE {$table} ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_place BIGINT DEFAULT NULL; CREATE INDEX {$index} ON {$table} (partition_tracking_name, partition_key, partition_place);\n"
            . "  MySQL and MariaDB: ALTER TABLE {$table} ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL, ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL, ADD COLUMN partition_place BIGINT DEFAULT NULL, ADD INDEX {$index} (partition_tracking_name, partition_key, partition_place);\n"
            . "  SQLite: ALTER TABLE {$table} ADD COLUMN partition_tracking_name VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_key VARCHAR(255) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN partition_place BIGINT DEFAULT NULL; CREATE INDEX {$index} ON {$table} (partition_tracking_name, partition_key, partition_place);\n\n"
            . 'Or stop holding partition keys: remove #[PartitionDeadLetter] and PartitionDeadLetterConfiguration.';
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
        return $connection->createSchemaManager()->tableExists($this->tableName);
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [$this->tableName, $this->isUsed, $this->consoleInvocationPrefix, $this->connectionReferenceName]
        );
    }

    public function buildTableSchema(): Table
    {
        $table = new Table($this->tableName);

        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('failed_at', Types::DATETIME_MUTABLE);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('headers', Types::TEXT);

        $table->addColumn('partition_tracking_name', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('partition_key', Types::STRING, ['length' => 255, 'notnull' => false]);
        $table->addColumn('partition_place', Types::BIGINT, ['notnull' => false]);

        $table->setPrimaryKey(['message_id']);
        $table->addIndex(['failed_at']);
        $table->addIndex(['partition_tracking_name', 'partition_key', 'partition_place'], $this->partitionIndexName());
        return $table;
    }

    private function partitionIndexName(): string
    {
        return $this->tableName . '_partition_idx';
    }
}
