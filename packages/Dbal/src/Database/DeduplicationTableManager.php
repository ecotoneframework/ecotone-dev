<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Dbal\Compatibility\SchemaManagerCompatibility;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
class DeduplicationTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'deduplication';

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
        return $connection->getDatabasePlatform()->getCreateTableSQL($this->buildTableSchema());
    }

    public function getDropTableSql(Connection $connection): string
    {
        return "DROP TABLE IF EXISTS {$this->tableName}";
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
            [$this->tableName, $this->isActive, $this->shouldAutoInitialize]
        );
    }

    private function buildTableSchema(): Table
    {
        $table = new Table($this->tableName);

        $table->addColumn('message_id', Types::STRING, ['length' => 255]);
        $table->addColumn('consumer_endpoint_id', Types::STRING, ['length' => 255]);
        $table->addColumn('routing_slip', Types::STRING, ['length' => 255]);
        $table->addColumn('handled_at', Types::BIGINT);

        $table->setPrimaryKey(['message_id', 'consumer_endpoint_id', 'routing_slip']);
        $table->addIndex(['handled_at']);

        return $table;
    }
}
