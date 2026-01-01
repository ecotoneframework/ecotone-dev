<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Ecotone\Dbal\DocumentStore\DbalDocumentStore;
use Ecotone\Messaging\Config\Container\Definition;

/**
 * licence Apache-2.0
 */
final class DocumentStoreTableManager implements DbalTableManager
{
    public function __construct(
        private string $tableName = DbalDocumentStore::ECOTONE_DOCUMENT_STORE,
    ) {
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->tableName]);
    }

    public function createTable(Connection $connection): void
    {
        $schemaManager = $connection->createSchemaManager();

        if ($schemaManager->tablesExist([$this->tableName])) {
            return;
        }

        foreach ($this->getCreateTableSql($connection) as $sql) {
            $connection->executeStatement($sql);
        }
    }

    public function dropTable(Connection $connection): void
    {
        $connection->executeStatement($this->getDropTableSql($connection));
    }

    public function getCreateTableSql(Connection $connection): array
    {
        $table = new Table($this->tableName);

        $table->addColumn('collection', 'string', ['length' => 255]);
        $table->addColumn('document_id', 'string', ['length' => 255]);
        $table->addColumn('document_type', 'text');
        $table->addColumn('document', 'json');
        $table->addColumn('updated_at', 'float', ['length' => 53]);

        $table->setPrimaryKey(['collection', 'document_id']);

        return $connection->getDatabasePlatform()->getCreateTableSQL($table);
    }

    public function getDropTableSql(Connection $connection): string
    {
        return "DROP TABLE IF EXISTS {$this->tableName}";
    }
}

