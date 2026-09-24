<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;

/**
 * licence Enterprise
 */
final class PostgresTaggedEventSchema implements TaggedEventSchema
{
    public function createTaggedEventsTableSql(string $tableName): array
    {
        $quoted = $this->quoteIdentifier($tableName);

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    tag_name VARCHAR(100) NOT NULL,
                    tag_value VARCHAR(255) NOT NULL,
                    stream_name VARCHAR(128) NOT NULL,
                    event_no BIGINT NOT NULL,
                    tag_sequence BIGINT NOT NULL,
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
                    tag_name VARCHAR(100) NOT NULL,
                    tag_value VARCHAR(255) NOT NULL,
                    version BIGINT NOT NULL,
                    PRIMARY KEY (tag_name, tag_value)
                ) WITH (fillfactor = 70)
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
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ? AND table_schema = ANY (current_schemas(false))',
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
            . "ON CONFLICT (tag_name, tag_value) DO UPDATE SET version = {$quoted}.version + 1 RETURNING version";
    }

    public function supportsReturningOnUpsert(): bool
    {
        return true;
    }

    public function bigIntPlaceholder(): string
    {
        return 'CAST(? AS BIGINT)';
    }

    public function idempotentInsertIndexRowSql(string $indexTableName, string $streamTableName): string
    {
        return 'INSERT INTO ' . $this->quoteIdentifier($indexTableName) . ' (tag_name, tag_value, stream_name, event_no, tag_sequence) '
            . 'SELECT ?, ?, ?, s.no, ' . $this->bigIntPlaceholder() . ' FROM ' . $this->quoteIdentifier($streamTableName) . ' s WHERE s.event_id = ? '
            . 'ON CONFLICT DO NOTHING';
    }
}
