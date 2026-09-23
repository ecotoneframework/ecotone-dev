<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\Tag;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * licence Enterprise
 */
final class TaggedEventSchemaFactory
{
    public static function for(Connection $connection): TaggedEventSchema
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => new PostgresTaggedEventSchema(),
            $platform instanceof MariaDBPlatform => new MariaDbTaggedEventSchema(),
            $platform instanceof SQLitePlatform => new SqliteTaggedEventSchema(),
            default => new MySqlTaggedEventSchema(),
        };
    }
}
