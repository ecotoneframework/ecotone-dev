<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * licence Apache-2.0
 */
final class EventStreamSchemaFactory
{
    public static function for(Connection $connection): EventStreamSchema
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => new PostgresEventStreamSchema(),
            $platform instanceof MariaDBPlatform => new MariaDbEventStreamSchema(),
            default => new MySqlEventStreamSchema(),
        };
    }
}
