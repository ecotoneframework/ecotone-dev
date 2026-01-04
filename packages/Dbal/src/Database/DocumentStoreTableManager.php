<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class DocumentStoreTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'document_store';

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

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName, $this->isActive, $this->shouldAutoInitialize]);
    }

    public function shouldBeInitializedAutomatically(): bool
    {
        return $this->shouldAutoInitialize;
    }

    public function createTable(Connection $connection): void
    {
        if ($this->isInitialized($connection)) {
            return;
        }

        SchemaManagerCompatibility::getSchemaManager($connection)->createTable($this->buildTableSchema());
    }

    public function dropTable(Connection $connection): void
    {
        $connection->executeStatement($this->getDropTableSql($connection));
    }

    public function getCreateTableSql(Connection $connection): array
    {
        return $connection->getDatabasePlatform()->getCreateTableSQL($this->buildTableSchema());
    }

    private function buildTableSchema(): Table
    {
        $table = new Table($this->tableName);

        $table->addColumn('collection', 'string', ['length' => 255]);
        $table->addColumn('document_id', 'string', ['length' => 255]);
        $table->addColumn('document_type', 'text');
        $table->addColumn('document', 'json');
        $table->addColumn('updated_at', 'float', ['length' => 53]);

        $table->setPrimaryKey(['collection', 'document_id']);

        return $table;
    }

    public function getDropTableSql(Connection $connection): string
    {
        return "DROP TABLE IF EXISTS {$this->tableName}";
    }

    public function isInitialized(Connection $connection): bool
    {
        return SchemaManagerCompatibility::tableExists($connection, $this->tableName);
    }
}
