<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;

/**
 * licence Enterprise
 */
final class SqliteTaggedEventSchema implements TaggedEventSchema
{
    public function createTaggedEventsTableSql(string $tableName): array
    {
        $quoted = $this->quoteIdentifier($tableName);

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    tag_name TEXT NOT NULL,
                    tag_value TEXT NOT NULL,
                    stream_name TEXT NOT NULL,
                    event_no INTEGER NOT NULL,
                    tag_sequence INTEGER NOT NULL,
                    PRIMARY KEY (tag_name, tag_value, stream_name, event_no)
                )
                SQL,
        ];
    }

    public function createTagVersionsTableSql(string $tableName): array
    {
        $quoted = $this->quoteIdentifier($tableName);

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    tag_name TEXT NOT NULL,
                    tag_value TEXT NOT NULL,
                    version INTEGER NOT NULL,
                    PRIMARY KEY (tag_name, tag_value)
                )
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
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$tableName]
        )->fetchOne();
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function insertInitialVersionSql(string $tableName): string
    {
        $quoted = $this->quoteIdentifier($tableName);

        return "INSERT INTO {$quoted} (tag_name, tag_value, version) VALUES (?, ?, 1) ON CONFLICT (tag_name, tag_value) DO NOTHING";
    }

    public function upsertIncrementVersionSql(string $tableName): string
    {
        $quoted = $this->quoteIdentifier($tableName);

        return "INSERT INTO {$quoted} (tag_name, tag_value, version) VALUES (?, ?, 1) "
            . "ON CONFLICT (tag_name, tag_value) DO UPDATE SET version = version + 1 RETURNING version";
    }

    public function supportsReturningOnUpsert(): bool
    {
        return true;
    }
}
