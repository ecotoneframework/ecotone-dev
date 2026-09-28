<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ecotone\EventSourcing\Database\TagTableManager;

/**
 * licence Enterprise
 */
final class TagSchemaVerifier
{
    /**
     * @return string[] problems found, each paired with the exact ALTER statement that fixes it
     */
    public function verifyTagTables(Connection $connection): array
    {
        $problems = [];
        $platform = $connection->getDatabasePlatform();

        $expectedPrimaryKeys = [
            TagTableManager::TAGGED_EVENTS_TABLE => ['tag_name', 'tag_value', 'stream_name', 'event_no'],
            TagTableManager::TAG_VERSIONS_TABLE => ['tag_name', 'tag_value'],
        ];

        foreach ($expectedPrimaryKeys as $tableName => $expectedColumns) {
            if (! $connection->createSchemaManager()->tablesExist([$tableName])) {
                $problems[] = sprintf(
                    "Table '%s' does not exist -- tagged appends and decision models cannot run without it. Fix:\n"
                    . 'ecotone:migration:database:setup --initialize --feature=%s (or --sql --feature=%s to print the DDL for your migration tool)',
                    $tableName,
                    TagTableManager::FEATURE_NAME,
                    TagTableManager::FEATURE_NAME,
                );
                continue;
            }

            $problems = [...$problems, ...$this->verifyPrimaryKey($connection, $tableName, $expectedColumns)];

            if ($platform instanceof AbstractMySQLPlatform) {
                $problems = [...$problems, ...$this->verifyMySqlCollation($connection, $tableName)];
            }
        }

        return $problems;
    }

    /**
     * @param string[] $expectedColumns
     * @return string[]
     */
    private function verifyPrimaryKey(Connection $connection, string $tableName, array $expectedColumns): array
    {
        $schemaManager = $connection->createSchemaManager();
        if (! $schemaManager->tablesExist([$tableName])) {
            return [];
        }

        $primaryKey = $schemaManager->introspectTable($tableName)->getPrimaryKey();
        $actualColumns = $primaryKey === null ? [] : $primaryKey->getColumns();

        if ($actualColumns === $expectedColumns) {
            return [];
        }

        return [sprintf(
            "Table '%s' has primary key (%s) instead of the expected (%s) -- conflict detection silently degrades without it. Fix:\n"
            . 'ALTER TABLE %s ADD PRIMARY KEY (%s);',
            $tableName,
            implode(', ', $actualColumns) ?: 'none',
            implode(', ', $expectedColumns),
            $tableName,
            implode(', ', $expectedColumns),
        )];
    }

    /**
     * @param string[] $legacyStreamTables
     * @return string[] problems found, each paired with the exact ALTER statement that fixes it
     */
    public function verifyLegacyStreamTables(Connection $connection, array $legacyStreamTables): array
    {
        $problems = [];
        $platform = $connection->getDatabasePlatform();

        foreach ($legacyStreamTables as $tableName) {
            if ($platform instanceof PostgreSQLPlatform) {
                $problems = [...$problems, ...$this->verifyPostgresNotNullConstraints($connection, $tableName)];
            } elseif ($platform instanceof AbstractMySQLPlatform) {
                $problems = [...$problems, ...$this->verifyMySqlAggregateColumnsNullable($connection, $tableName)];
            }
        }

        return $problems;
    }

    /**
     * @return string[]
     */
    private function verifyMySqlCollation(Connection $connection, string $tableName): array
    {
        $rows = $connection->executeQuery(
            'SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.columns '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?, ?)',
            [$tableName, 'tag_name', 'tag_value', 'stream_name']
        )->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        foreach ($rows as $row) {
            if ($row['COLLATION_NAME'] !== 'utf8mb4_bin') {
                return [sprintf(
                    "Table '%s' does not use utf8mb4_bin collation on its tag columns (found '%s' on '%s') -- "
                    . "'ABC' and 'abc' would collide as the same tag value. Fix:\n"
                    . 'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;',
                    $tableName,
                    $row['COLLATION_NAME'],
                    $row['COLUMN_NAME'],
                    $tableName,
                )];
            }
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function verifyPostgresNotNullConstraints(Connection $connection, string $tableName): array
    {
        $rows = $connection->executeQuery(
            "SELECT conname FROM pg_constraint c JOIN pg_class t ON c.conrelid = t.oid "
            . "WHERE t.relname = ? AND c.conname IN ('aggregate_version_not_null', 'aggregate_type_not_null', 'aggregate_id_not_null')",
            [$tableName]
        )->fetchFirstColumn();

        if ($rows === []) {
            return [];
        }

        $drops = implode(', ', array_map(static fn (string $name) => "DROP CONSTRAINT IF EXISTS {$name}", $rows));

        return [sprintf(
            "Table \"%s\" still enforces NOT NULL on aggregate columns via %s -- an aggregate-less decision-model event would be rejected. Fix:\n"
            . 'SET lock_timeout = \'2s\'; ALTER TABLE "%s" %s;',
            $tableName,
            implode(', ', $rows),
            $tableName,
            $drops,
        )];
    }

    /**
     * @return string[]
     */
    private function verifyMySqlAggregateColumnsNullable(Connection $connection, string $tableName): array
    {
        $rows = $connection->executeQuery(
            'SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.columns '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ('aggregate_version', 'aggregate_type', 'aggregate_id') AND IS_NULLABLE = 'NO'",
            [$tableName]
        )->fetchFirstColumn();

        if ($rows === []) {
            return [];
        }

        return [sprintf(
            "Table `%s` still has NOT NULL generated columns (%s) -- an aggregate-less decision-model event would be rejected. "
            . 'Fix: MODIFY each generated column, restating its expression without NOT NULL, on `%s`.',
            $tableName,
            implode(', ', $rows),
            $tableName,
        )];
    }
}
