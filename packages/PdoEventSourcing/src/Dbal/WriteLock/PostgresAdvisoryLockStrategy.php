<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Dbal\WriteLock;

use Doctrine\DBAL\Connection;

/**
 * licence BSD-3-Clause
 * code comes from https://github.com/prooph/pdo-event-store
 * (c) 2016-2025 Alexander Miertsch <kontakt@codeliner.ws>
 * (c) 2016-2025 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 */
final class PostgresAdvisoryLockStrategy implements WriteLockStrategy
{
    public function getLock(Connection $connection, string $name): bool
    {
        $connection->executeQuery('SELECT pg_advisory_lock(hashtext(?))', [$name]);

        return true;
    }

    public function releaseLock(Connection $connection, string $name): bool
    {
        $connection->executeQuery('SELECT pg_advisory_unlock(hashtext(?))', [$name]);

        return true;
    }
}
