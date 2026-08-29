<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

/**
 * licence BSD-3-Clause
 * code comes from https://github.com/prooph/pdo-event-store
 * (c) 2016-2025 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2016-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 */
final class MariaDbEventStreamSchema extends MySqlEventStreamSchema
{
    public function createTableSql(string $tableName): array
    {
        return [
            <<<SQL
                CREATE TABLE IF NOT EXISTS `{$tableName}` (
                    `no` BIGINT(20) NOT NULL AUTO_INCREMENT,
                    `event_id` CHAR(36) COLLATE utf8mb4_bin NOT NULL,
                    `event_name` VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
                    `payload` LONGTEXT NOT NULL,
                    `metadata` LONGTEXT NOT NULL,
                    `created_at` DATETIME(6) NOT NULL,
                    `aggregate_version` INT(11) UNSIGNED GENERATED ALWAYS AS (JSON_EXTRACT(metadata, '$._aggregate_version')) STORED,
                    `aggregate_id` VARCHAR(150) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._aggregate_id'))) STORED,
                    `aggregate_type` VARCHAR(150) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$._aggregate_type'))) STORED,
                    CHECK (`payload` IS NOT NULL AND JSON_VALID(`payload`)),
                    CHECK (`metadata` IS NOT NULL AND JSON_VALID(`metadata`)),
                    PRIMARY KEY (`no`),
                    UNIQUE KEY `ix_event_id` (`event_id`),
                    UNIQUE KEY `ix_unique_event` (`aggregate_type`, `aggregate_id`, `aggregate_version`),
                    KEY `ix_query_aggregate` (`aggregate_type`,`aggregate_id`,`no`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
                SQL,
        ];
    }
}
