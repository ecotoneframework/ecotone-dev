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
            . "ON CONFLICT (tag_name, tag_value) DO UPDATE SET version = {$quoted}.version + 1";
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
