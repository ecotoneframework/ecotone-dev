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
final class MetadataLockStrategy implements WriteLockStrategy
{
    public function __construct(private int $timeout = 0xffffff)
    {
    }

    public function getLock(Connection $connection, string $name): bool
    {
        $lockStatus = $connection->executeQuery('SELECT GET_LOCK(?, ?)', [$name, $this->timeout])->fetchOne();

        return $lockStatus === 1 || $lockStatus === '1';
    }

    public function releaseLock(Connection $connection, string $name): bool
    {
        $connection->executeQuery('SELECT RELEASE_LOCK(?)', [$name])->fetchOne();

        return true;
    }
}
