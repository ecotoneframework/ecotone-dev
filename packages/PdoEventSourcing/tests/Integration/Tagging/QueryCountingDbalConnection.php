<?php

declare(strict_types=1);

namespace Test\Ecotone\EventSourcing\Integration\Tagging;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;

use function ltrim;
use function str_contains;
use function stripos;

/**
 * licence Enterprise
 * @internal
 */
final class QueryCountingDbalConnection extends Connection
{
    public static int $fetchTagFlagsSelectCount = 0;

    public static function resetCount(): void
    {
        self::$fetchTagFlagsSelectCount = 0;
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        if (self::isFetchTagFlagsSelect($sql)) {
            self::$fetchTagFlagsSelectCount++;
        }

        return parent::executeQuery($sql, $params, $types, $qcp);
    }

    private static function isFetchTagFlagsSelect(string $sql): bool
    {
        return stripos(ltrim($sql), 'SELECT') === 0
            && str_contains($sql, 'ecotone_tagged_events')
            && stripos($sql, 'GROUP BY') !== false;
    }
}
