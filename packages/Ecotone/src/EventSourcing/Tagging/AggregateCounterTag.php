<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

/**
 * licence Enterprise
 */
final class AggregateCounterTag
{
    public const NAME_PREFIX = 'aggregate_';

    public static function nameFor(string $aggregateType): string
    {
        return self::NAME_PREFIX . $aggregateType;
    }
}
