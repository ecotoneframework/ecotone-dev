<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;

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
final class MySqlTaggedEventSchema implements TaggedEventSchema
{
    public function createTaggedEventsTableSql(string $tableName): array
    {
        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS `{$tableName}` (
                    `tag_name` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
                    `tag_value` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
                    `stream_name` VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
                    `event_no` BIGINT(20) NOT NULL,
                    `tag_sequence` BIGINT(20) NOT NULL,
                    PRIMARY KEY (`tag_name`, `tag_value`, `stream_name`, `event_no`)
                ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
                SQL,
        ];
    }

    public function createTagVersionsTableSql(string $tableName): array
    {
        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS `{$tableName}` (
                    `tag_name` VARCHAR(100) COLLATE utf8mb4_bin NOT NULL,
                    `tag_value` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
                    `version` BIGINT(20) NOT NULL,
                    PRIMARY KEY (`tag_name`, `tag_value`)
                ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
                SQL,
        ];
    }

    public function dropTableSql(string $tableName): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName);
    }

    public function tableExists(Connection $connection, string $tableName): bool
    {
        return (bool) $connection->executeQuery(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName]
        )->fetchOne();
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    public function insertInitialVersionSql(string $tableName): string
    {
        $quoted = $this->quoteIdentifier($tableName);

        return "INSERT INTO {$quoted} (tag_name, tag_value, version) VALUES (?, ?, 1)";
    }

    public function upsertIncrementVersionSql(string $tableName): string
    {
        $quoted = $this->quoteIdentifier($tableName);

        return "INSERT INTO {$quoted} (tag_name, tag_value, version) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE version = version + 1";
    }

    public function insertPlaceholderVersionSql(string $tableName): string
    {
        $quoted = $this->quoteIdentifier($tableName);

        return "INSERT IGNORE INTO {$quoted} (tag_name, tag_value, version) VALUES (?, ?, 0)";
    }

    public function lockedVersion(Connection $connection, string $tableName, string $tagName, string $tagValue): int
    {
        return (int) $connection->executeQuery(
            'SELECT version FROM ' . $this->quoteIdentifier($tableName) . ' WHERE tag_name = ? AND tag_value = ? LOCK IN SHARE MODE',
            [$tagName, $tagValue]
        )->fetchOne();
    }

    public function bigIntPlaceholder(): string
    {
        return '?';
    }

    public function idempotentInsertIndexRowSql(string $indexTableName, string $streamTableName): string
    {
        return 'INSERT IGNORE INTO ' . $this->quoteIdentifier($indexTableName) . ' (tag_name, tag_value, stream_name, event_no, tag_sequence) '
            . 'SELECT ?, ?, ?, s.no, ? FROM ' . $this->quoteIdentifier($streamTableName) . ' s WHERE s.event_id = ?';
    }
}
