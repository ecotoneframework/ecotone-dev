<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;

/**
 * licence Enterprise
 */
interface TaggedEventSchema
{
    /**
     * @return array<string>
     */
    public function createTaggedEventsTableSql(string $tableName): array;

    /**
     * @return array<string>
     */
    public function createTagVersionsTableSql(string $tableName): array;

    public function dropTableSql(string $tableName): string;

    public function tableExists(Connection $connection, string $tableName): bool;

    public function quoteIdentifier(string $identifier): string;

    public function insertInitialVersionSql(string $tableName): string;

    public function upsertIncrementVersionSql(string $tableName): string;

    public function bigIntPlaceholder(): string;

    /**
     * INSERT ... SELECT of one tagged-event index row, silently doing nothing when the (tag_name, tag_value,
     * stream_name, event_no) primary key already exists -- used by the backfill command so re-running it is safe.
     * Parameters, in order: tag_name, tag_value, stream_name, tag_sequence, event_id.
     */
    public function idempotentInsertIndexRowSql(string $indexTableName, string $streamTableName): string;
}
