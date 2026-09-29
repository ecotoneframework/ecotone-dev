<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\Table;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class DocumentStoreTableManager implements DbalTableManager
{
    use AutomaticTableInitializationTrait;

    public const FEATURE_NAME = 'document_store';

    public function __construct(
        private string $tableName,
        private bool $isUsed,
        private bool $shouldAutoInitialize,
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

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName, $this->isUsed, $this->shouldAutoInitialize, $this->consoleInvocationPrefix, $this->connectionReferenceName]);
    }

    public function getMissingTableInstructions(Connection $connection): string
    {
        return AutomaticTableInitializationSupport::isSupported($connection)
            ? MissingTableInstructions::build(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix, $this->connectionReferenceName)
            : MissingTableInstructions::buildForUnsupportedAutomaticInitialization(self::FEATURE_NAME, $this->tableName, $this->consoleInvocationPrefix, $this->connectionReferenceName);
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

    public function dropTable(Connection $connection): void
    {
        $connection->executeStatement($this->getDropTableSql($connection));
    }

    public function getCreateTableSql(Connection $connection): array
    {
        return DbalIdempotentDdl::withIfNotExistsAll($connection->getDatabasePlatform()->getCreateTableSQL($this->buildTableSchema()));
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
        return $connection->createSchemaManager()->tableExists($this->tableName);
    }
}
