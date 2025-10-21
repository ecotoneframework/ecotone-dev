<?php

/*
 * licence Apache-2.0
 */
declare(strict_types=1);

namespace Ecotone\Dbal\DbalTransaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Throwable;

class ImplicitCommit
{
    public static function isImplicitCommitException(Throwable $exception, Connection $connection): bool
    {
        if (! ($connection->getDriver()->getDatabasePlatform($connection) instanceof MySQLPlatform)) {
            return false;
        }

        $patterns = [
            'No active transaction',
            'There is no active transaction',
            'Transaction not active',
            'not in a transaction',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($exception->getMessage(), $pattern)) {
                return true;
            }
        }

        return false;
    }
}
