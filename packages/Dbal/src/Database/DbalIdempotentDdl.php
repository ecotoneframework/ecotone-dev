<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

/**
 * licence Apache-2.0
 */
final class DbalIdempotentDdl
{
    /**
     * @param string|array<string> $createTableSql
     * @return string|array<string>
     */
    public static function withIfNotExists(string|array $createTableSql): string|array
    {
        if (is_array($createTableSql)) {
            return self::withIfNotExistsAll($createTableSql);
        }

        return self::prefixStatement($createTableSql);
    }

    /**
     * @param array<string> $createTableSqlStatements
     * @return array<string>
     */
    public static function withIfNotExistsAll(array $createTableSqlStatements): array
    {
        return array_map(self::prefixStatement(...), $createTableSqlStatements);
    }

    private static function prefixStatement(string $sql): string
    {
        if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS')) {
            return $sql;
        }

        if (str_starts_with($sql, 'CREATE TABLE ')) {
            return 'CREATE TABLE IF NOT EXISTS ' . substr($sql, strlen('CREATE TABLE '));
        }

        return $sql;
    }
}
