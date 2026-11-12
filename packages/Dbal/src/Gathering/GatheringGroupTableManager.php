<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Gathering;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Ecotone\Api\ExtensionObject\ModulePackageList;
use Ecotone\Dbal\Database\DbalIdempotentDdl;
use Ecotone\Dbal\Database\DbalTableManager;
use Ecotone\Dbal\Database\MissingTableInstructions;
use Ecotone\Messaging\Config\Container\Definition;

/**
 *
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
 *
 */
final class GatheringGroupTableManager implements DbalTableManager
{
    public const FEATURE_NAME = 'gathering';

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

    private function buildTableSchema(): Table
    {
        $table = new Table($this->tableName);

        $table->addColumn('group_id', Types::STRING, ['length' => 64]);
        $table->addColumn('gather_channel_name', Types::STRING, ['length' => 255]);
        $table->addColumn('size', Types::INTEGER);
        $table->setPrimaryKey(['group_id']);
        $table->addIndex(['gather_channel_name']);

        return $table;
    }
}
