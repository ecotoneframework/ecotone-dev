<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

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
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
 */
final class TaggedEventSchemaFactory
{
    public static function for(Connection $connection): TaggedEventSchema
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => new PostgresTaggedEventSchema(),
            $platform instanceof SQLitePlatform => new SqliteTaggedEventSchema(),
            default => new MySqlTaggedEventSchema(),
        };
    }
}
