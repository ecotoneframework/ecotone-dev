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

    /**
     * Guarded insert of the tag's first version. Zero affected rows, or a duplicate key error caught by the
     * caller, both mean a concurrent writer already created the row -> a captured-version-0 conflict.
     */
    public function insertInitialVersionSql(string $tableName): string;

    /**
     * Unconditional insert-or-bump of a tag's version, used both for tags outside the append condition and for
     * the guarded path once the row is known to exist. Returns the row's new version when supportsReturningOnUpsert()
     * is true; otherwise the caller must SELECT it back inside the same transaction.
     */
    public function upsertIncrementVersionSql(string $tableName): string;

    public function supportsReturningOnUpsert(): bool;

    /**
     * The placeholder expression for a bigint parameter inside a `SELECT ? AS x` branch of a UNION ALL. Some
     * platforms (PostgreSQL) cannot infer an untyped parameter's type from the target column of an
     * INSERT ... SELECT across a UNION and default it to text, so it must be cast explicitly.
     */
    public function bigIntPlaceholder(): string;
}
