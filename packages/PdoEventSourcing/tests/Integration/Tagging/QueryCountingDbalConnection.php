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
    /**
     * Counts DbalTaggedEventReader::fetchTagFlags() calls only -- its "GROUP BY stream_name, event_no"
     * shape is unique to that one query, fired exactly once per loadByCriteria(), regardless of how many
     * branches were or()'d together or which DB engine is used. Unlike a table-name match, this can't be
     * confused with the append side's own tag-version-bump SELECTs (DbalTagVersionRegister::currentTagVersion()),
     * whose count varies with how many tags the appended event carries, not with the number of injected models.
     */
    public static int $loadByCriteriaSelectCount = 0;

    public static function resetCount(): void
    {
        self::$loadByCriteriaSelectCount = 0;
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        if (self::isFetchTagFlagsSelect($sql)) {
            self::$loadByCriteriaSelectCount++;
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
