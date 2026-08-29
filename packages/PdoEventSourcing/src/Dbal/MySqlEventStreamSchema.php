<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use Doctrine\DBAL\Connection;
use Ecotone\EventSourcing\EventStore\Operator;

use function var_export;

/**
 * licence BSD-3-Clause
 * code comes from https://github.com/prooph/pdo-event-store
 * (c) 2016-2025 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2016-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 */
class MySqlEventStreamSchema implements EventStreamSchema
{
    protected const INDEXED_METADATA_FIELDS = [
        '_aggregate_id' => 'aggregate_id',
        '_aggregate_type' => 'aggregate_type',
        '_aggregate_version' => 'aggregate_version',
    ];

    public function createTableSql(string $tableName): array
    {
        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS `{$tableName}` (
                    `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                    `event_id` CHAR(36) COLLATE utf8mb4_bin NOT NULL,
                    `event_name` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
                    `payload` JSON NOT NULL,
                    `metadata` JSON NOT NULL,
                    `created_at` DATETIME(6) NOT NULL,
                    `aggregate_version` INT(11) UNSIGNED GENERATED ALWAYS AS (JSON_EXTRACT(metadata, '$._aggregate_version')) STORED,
                    `aggregate_id` VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._aggregate_id'))) STORED,
                    `aggregate_type` VARCHAR(150) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._aggregate_type'))) STORED,
                    PRIMARY KEY (`no`),
                    UNIQUE KEY `ix_event_id` (`event_id`),
                    UNIQUE KEY `ix_unique_event` (`aggregate_type`, `aggregate_id`, `aggregate_version`),
                    KEY `ix_query_aggregate` (`aggregate_type`,`aggregate_id`,`no`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
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

    public function metadataFieldExpression(string $field, bool $comparedWithInteger): string
    {
        if (isset(static::INDEXED_METADATA_FIELDS[$field])) {
            return '`' . static::INDEXED_METADATA_FIELDS[$field] . '`';
        }

        return "JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.{$field}'))";
    }

    public function operatorSql(Operator $operator): string
    {
        return $operator === Operator::REGEX ? 'REGEXP' : $operator->value;
    }

    public function booleanLiteral(bool $value): string
    {
        return var_export($value, true);
    }
}
