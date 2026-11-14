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
final class GatheringTableManager implements DbalTableManager
{
    public function __construct(private ?string $consoleInvocationPrefix, private string $connectionReferenceName)
    {
    }

    public function getFeatureName(): string
    {
        return 'gathering';
    }

    public function getModulePackageName(): string
    {
        return ModulePackageList::DBAL_PACKAGE;
    }

    public function isUsed(): bool
    {
        return true;
    }

    public function getCreateTableSql(Connection $connection): string|array
    {
        $statements = [];
        foreach ($this->schemas() as $table) {
            $statements = [...$statements, ...$connection->getDatabasePlatform()->getCreateTableSQL($table)];
        }

        return DbalIdempotentDdl::withIfNotExists($statements);
    }

    public function getDropTableSql(Connection $connection): string
    {
        return 'DROP TABLE IF EXISTS ' . DbalGatheredGroupStore::PART_TABLE . ', ' . DbalGatheredGroupStore::GROUP_TABLE;
    }

    public function createTable(Connection $connection): void
    {
        foreach ($this->schemas() as $table) {
            if ($connection->createSchemaManager()->tableExists($table->getName())) {
                continue;
            }
            try {
                $connection->createSchemaManager()->createTable($table);
            } catch (TableExistsException) {
            }
        }
    }

    public function dropTable(Connection $connection): void
    {
        foreach ([DbalGatheredGroupStore::PART_TABLE, DbalGatheredGroupStore::GROUP_TABLE] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    public function isInitialized(Connection $connection): bool
    {
        return $connection->createSchemaManager()->tablesExist([DbalGatheredGroupStore::GROUP_TABLE, DbalGatheredGroupStore::PART_TABLE]);
    }

    public function getMissingTableInstructions(): string
    {
        return MissingTableInstructions::build(featureName: 'gathering', tableName: DbalGatheredGroupStore::GROUP_TABLE . ', ' . DbalGatheredGroupStore::PART_TABLE, consoleInvocationPrefix: $this->consoleInvocationPrefix, connectionReferenceName: $this->connectionReferenceName);
    }

    public function getDefinition(): Definition
    {
        return new Definition(self::class, [$this->consoleInvocationPrefix, $this->connectionReferenceName]);
    }

    private function schemas(): array
    {
        $group = new Table(DbalGatheredGroupStore::GROUP_TABLE);
        $group->addColumn(name: 'group_id', typeName: Types::STRING, options: ['length' => 64]);
        $group->addColumn(name: 'gather_channel_name', typeName: Types::STRING, options: ['length' => 255]);
        $group->addColumn(name: 'endpoint_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false]);
        $group->addColumn(name: 'group_key', typeName: Types::TEXT, options: ['notnull' => false]);
        $group->addColumn(name: 'group_key_hash', typeName: Types::STRING, options: ['length' => 64, 'notnull' => false]);
        $group->addColumn(name: 'opened_at', typeName: Types::BIGINT, options: ['notnull' => false]);
        $group->addColumn(name: 'release_reason', typeName: Types::STRING, options: ['length' => 32, 'notnull' => false]);
        $group->addColumn(name: 'open_slot', typeName: Types::SMALLINT, options: ['notnull' => false]);
        $group->addUniqueIndex(['endpoint_id', 'group_key_hash', 'open_slot']);
        $group->addColumn('size', Types::INTEGER);
        $group->addColumn('arrived', Types::INTEGER);
        $group->addColumn('version', Types::INTEGER);
        $group->addColumn(name: 'late_part_count', typeName: Types::INTEGER, options: ['default' => 0]);
        $group->addColumn(name: 'deadline', typeName: Types::BIGINT, options: ['notnull' => false]);
        $group->addColumn(name: 'closed_at', typeName: Types::BIGINT, options: ['notnull' => false]);
        $group->addColumn(name: 'state', typeName: Types::STRING, options: ['length' => 16]);
        $group->addColumn('original_message', Types::TEXT);
        $group->setPrimaryKey(['group_id']);
        $group->addIndex(['gather_channel_name', 'state']);
        $part = new Table(DbalGatheredGroupStore::PART_TABLE);
        $part->addColumn(name: 'group_id', typeName: Types::STRING, options: ['length' => 64]);
        $part->addColumn('part_number', Types::INTEGER);
        $part->addColumn(name: 'endpoint_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false]);
        $part->addColumn(name: 'message_id', typeName: Types::STRING, options: ['length' => 255, 'notnull' => false]);
        $part->addUniqueIndex(['endpoint_id', 'message_id']);
        $part->addColumn('report', Types::TEXT);
        $part->setPrimaryKey(['group_id', 'part_number']);

        return [$group, $part];
    }
}
