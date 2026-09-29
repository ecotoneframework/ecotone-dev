<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel\Snapshot;

use function implode;
use function sha1;
use function sort;

/**
 * licence Enterprise
 */
final class DecisionModelFoldShape
{
    /**
     * @param class-string $modelClass
     * @param class-string[] $handledEventClasses
     * @param string[] $scopeNames
     */
    public static function of(string $modelClass, array $handledEventClasses, array $scopeNames): string
    {
        sort($handledEventClasses);
        sort($scopeNames);

        return sha1(implode("\0", [$modelClass, implode(',', $handledEventClasses), implode(',', $scopeNames)]));
    }
}
