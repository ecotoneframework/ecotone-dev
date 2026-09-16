<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
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
        return MissingTableInstructions::build(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix);
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

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    public function getDefinition(): Definition
    {
        return new Definition(
            self::class,
            [$this->tableName, $this->isUsed, $this->shouldAutoInitialize, $this->consoleInvocationPrefix]
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
