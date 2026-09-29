<?php

declare(strict_types=1);

namespace Ecotone\Dbal\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * licence Apache-2.0
 */
final class AutomaticTableInitializationSupport
{
    public static function isSupported(Connection $connection): bool
    {
        return ! $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }
}
