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
final class EnqueueTableManager implements DbalTableManager
{
    public const DEFAULT_TABLE_NAME = 'enqueue';
    public const FEATURE_NAME = 'message_queue';

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

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName, $this->isUsed, $this->shouldAutoInitialize]);
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

        $table->addColumn('id', 'guid', ['length' => 16, 'fixed' => true]);
        $table->addColumn('published_at', 'bigint');
        $table->addColumn('body', 'text', ['notnull' => false]);
        $table->addColumn('headers', 'text', ['notnull' => false]);
        $table->addColumn('properties', 'text', ['notnull' => false]);
        $table->addColumn('redelivered', 'boolean', ['notnull' => false]);
        $table->addColumn('queue', 'string', ['length' => 255]);
        $table->addColumn('priority', 'integer', ['notnull' => false]);
        $table->addColumn('delayed_until', 'bigint', ['notnull' => false]);
        $table->addColumn('time_to_live', 'bigint', ['notnull' => false]);
        $table->addColumn('delivery_id', 'guid', ['length' => 16, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('redeliver_after', 'bigint', ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['priority', 'published_at', 'queue', 'delivery_id', 'delayed_until', 'id']);
        $table->addIndex(['redeliver_after', 'delivery_id']);
        $table->addIndex(['time_to_live', 'delivery_id']);
        $table->addIndex(['delivery_id']);

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
