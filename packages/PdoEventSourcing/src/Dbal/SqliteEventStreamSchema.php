<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\Api\EventSourcing\Operator;

use function preg_replace;
use function sha1;
use function substr;

/**
 * licence Apache-2.0
 */
final class SqliteEventStreamSchema implements EventStreamSchema
{
    public function createTableSql(string $tableName): array
    {
        $quoted = $this->quoteIdentifier($tableName);
        $indexPrefix = substr((string) preg_replace('/[^a-zA-Z0-9_]/', '_', $tableName), 0, 30) . '_' . substr(sha1($tableName), 0, 8);

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    no INTEGER PRIMARY KEY,
                    event_id TEXT NOT NULL,
                    event_name TEXT NOT NULL,
                    payload TEXT NOT NULL,
                    metadata TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE (event_id)
                )
                SQL,
            <<<SQL
                CREATE UNIQUE INDEX IF NOT EXISTS ix_{$indexPrefix}_unique_event ON {$quoted}
                (CAST(json_extract(metadata, '$._aggregate_type') AS TEXT), CAST(json_extract(metadata, '$._aggregate_id') AS TEXT), CAST(json_extract(metadata, '$._aggregate_version') AS TEXT))
                SQL,
            <<<SQL
                CREATE INDEX IF NOT EXISTS ix_{$indexPrefix}_aggregate ON {$quoted}
                (CAST(json_extract(metadata, '$._aggregate_type') AS TEXT), CAST(json_extract(metadata, '$._aggregate_id') AS TEXT), no)
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

    public function metadataFieldExpression(string $field, bool $comparedWithInteger): string
    {
        if ($comparedWithInteger) {
            return "json_extract(metadata, '$.{$field}')";
        }

        return "CAST(json_extract(metadata, '$.{$field}') AS TEXT)";
    }

    public function operatorSql(Operator $operator): string
    {
        return $operator === Operator::REGEX ? 'REGEXP' : $operator->value;
    }

    public function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }
}
