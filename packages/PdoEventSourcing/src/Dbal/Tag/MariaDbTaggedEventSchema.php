<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

/**
 * licence Enterprise
 */
final class MariaDbTaggedEventSchema extends MySqlTaggedEventSchema
{
    public function upsertIncrementVersionSql(string $tableName): string
    {
        $quoted = $this->quoteIdentifier($tableName);

        return "INSERT INTO {$quoted} (tag_name, tag_value, version) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE version = version + 1 RETURNING version";
    }

    public function supportsReturningOnUpsert(): bool
    {
        return true;
    }
}
