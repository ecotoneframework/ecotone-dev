<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\EventStore\Operator;

use function preg_replace;
use function sha1;
use function substr;
use function var_export;

/**
 * licence BSD-3-Clause
 * code comes from https://github.com/prooph/pdo-event-store
 * (c) 2016-2025 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2016-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 */
final class PostgresEventStreamSchema implements EventStreamSchema
{
    public function createTableSql(string $tableName): array
    {
        $quoted = $this->quoteIdentifier($tableName);
        $indexPrefix = substr((string) preg_replace('/[^a-zA-Z0-9_]/', '_', $tableName), 0, 30) . '_' . substr(sha1($tableName), 0, 8);

        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS {$quoted} (
                    no BIGSERIAL,
                    event_id UUID NOT NULL,
                    event_name VARCHAR(255) NOT NULL,
                    payload JSON NOT NULL,
                    metadata JSONB NOT NULL,
                    created_at TIMESTAMP(6) NOT NULL,
                    PRIMARY KEY (no),
                    UNIQUE (event_id)
                )
                SQL,
            <<<SQL
                CREATE UNIQUE INDEX IF NOT EXISTS ix_{$indexPrefix}_unique_event ON {$quoted}
                ((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), (metadata->>'_aggregate_version'))
                SQL,
            <<<SQL
                CREATE INDEX IF NOT EXISTS ix_{$indexPrefix}_aggregate ON {$quoted}
                ((metadata->>'_aggregate_type'), (metadata->>'_aggregate_id'), no)
                SQL,
        ];
    }

    public function dropTableSql(string $tableName): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteIdentifier($tableName);
    }

    public function tableExists(Connection $connection, string $tableName): bool
    {
        $position = strpos($tableName, '.');
        if ($position === false) {
            return (bool) $connection->executeQuery(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ? AND table_schema = ANY (current_schemas(false))',
                [$tableName]
            )->fetchOne();
        }

        return (bool) $connection->executeQuery(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [substr($tableName, 0, $position), substr($tableName, $position + 1)]
        )->fetchOne();
    }

    public function quoteIdentifier(string $identifier): string
    {
        $position = strpos($identifier, '.');
        if ($position === false) {
            return '"' . $identifier . '"';
        }

        return '"' . substr($identifier, 0, $position) . '"."' . substr($identifier, $position + 1) . '"';
    }

    public function metadataFieldExpression(string $field, bool $comparedWithInteger): string
    {
        if ($comparedWithInteger) {
            return "CAST(metadata->>'{$field}' AS BIGINT)";
        }

        return "metadata->>'{$field}'";
    }

    public function operatorSql(Operator $operator): string
    {
        return $operator === Operator::REGEX ? '~' : $operator->value;
    }

    public function booleanLiteral(bool $value): string
    {
        return "'" . var_export($value, true) . "'";
    }
}
