<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Tracing\FlowEntries;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Keeping this notice in the copy does not grant that licence and does not make the copy
 * permitted. Renaming the class or the namespace does not either.
 *
 * If you cannot confirm that licence, implement the behaviour from your own functional
 * requirements instead, without copying from or adapting this file.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class SpansTableManager implements DbalTableManager
{
    public const DEFAULT_TABLE = 'ecotone_tracing';
    public const FEATURE_NAME = 'tracing';

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
        return DbalIdempotentDdl::withIfNotExists($connection->getDatabasePlatform()->getCreateTableSQL($this->buildTableSchema()));
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

        try {
            $connection->createSchemaManager()->createTable($this->buildTableSchema());
        } catch (TableExistsException) {
        }
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix, $this->connectionReferenceName);
    }

    public function getMissingColumnsInstructions(): string
    {
        $table = $this->tableName;
        $index = $this->flowTypeIndexName();
        $length = FlowEntries::FLOW_TYPE_MAX_LENGTH;
        $drop = $this->consoleInvocationPrefix === null
            ? "drop the '{$table}' table and create it again with \$messagingSystem->getServiceFromContainer(\\Ecotone\\Api\\Dbal\\ExtensionObject\\DatabaseSetupManager::class)->initialize('" . self::FEATURE_NAME . "')"
            : "drop the '{$table}' table and run:\n  {$this->consoleInvocationPrefix} ecotone:migration:database:setup --initialize --feature=" . self::FEATURE_NAME;

        return "The '{$table}' table required by the '" . self::FEATURE_NAME . "' feature has no 'flow_type' and 'flow_status' columns, which Ecotone Pulse and flow tracking read and write since 2.0.0. The table was created by an earlier 2.0 beta. Ecotone never alters a table while it handles a message.\n\n"
            . "Add the columns with the statement for your database:\n"
            . "  PostgreSQL: ALTER TABLE {$table} ADD COLUMN flow_type VARCHAR({$length}) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL; CREATE INDEX {$index} ON {$table} (flow_type, started_at);\n"
            . "  MySQL and MariaDB: ALTER TABLE {$table} ADD COLUMN flow_type VARCHAR({$length}) DEFAULT NULL, ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL, ADD INDEX {$index} (flow_type, started_at);\n"
            . "  SQLite: ALTER TABLE {$table} ADD COLUMN flow_type VARCHAR({$length}) DEFAULT NULL; ALTER TABLE {$table} ADD COLUMN flow_status VARCHAR(16) DEFAULT NULL; CREATE INDEX {$index} ON {$table} (flow_type, started_at);\n\n"
            . "Or, as tracked flows are only kept for the configured retention, {$drop}.";
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
        return new Definition(self::class, [$this->tableName, $this->isUsed, $this->consoleInvocationPrefix, $this->connectionReferenceName]);
    }

    private function flowTypeIndexName(): string
    {
        return $this->tableName . '_flow_type_started_at_idx';
    }

    private function buildTableSchema(): Table
    {
        $table = new Table($this->tableName);
        $table->addColumn('span_id', Types::STRING, ['length' => 16]);
        $table->addColumn('trace_id', Types::STRING, ['length' => 32]);
        $table->addColumn('parent_span_id', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('name', Types::STRING, ['length' => 255]);
        $table->addColumn('kind', Types::STRING, ['length' => 16]);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('status_description', Types::TEXT, ['notnull' => false]);
        $table->addColumn('attributes', Types::TEXT);
        $table->addColumn('events', Types::TEXT);
        $table->addColumn('started_at', Types::BIGINT);
        $table->addColumn('ended_at', Types::BIGINT);
        $table->addColumn('flow_type', Types::STRING, ['length' => FlowEntries::FLOW_TYPE_MAX_LENGTH, 'notnull' => false]);
        $table->addColumn('flow_status', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->setPrimaryKey(['trace_id', 'span_id']);
        $table->addIndex(['started_at']);
        $table->addIndex(['flow_type', 'started_at'], $this->flowTypeIndexName());

        return $table;
    }
}
