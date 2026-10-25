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

    public function insertPlaceholderVersionSql(string $tableName): string;

    public function lockedVersion(Connection $connection, string $tableName, string $tagName, string $tagValue): int;

    public function bigIntPlaceholder(): string;

    /**
     * INSERT ... SELECT of one tagged-event index row, silently doing nothing when the (tag_name, tag_value,
     * stream_name, event_no) primary key already exists -- used by the backfill command so re-running it is safe.
     * Parameters, in order: tag_name, tag_value, stream_name, tag_sequence, event_id.
     */
    public function idempotentInsertIndexRowSql(string $indexTableName, string $streamTableName): string;
}
