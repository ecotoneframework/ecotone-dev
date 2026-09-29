<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use function array_key_exists;

use Ecotone\Api\Attribute\DecisionModel;
use ReflectionClass;

/**
 * licence Enterprise
 */
final class DecisionModelReflection
{
    private static array $cache = [];

    private static array $backingAggregateCache = [];

    public static function isDecisionModel(string $className): bool
    {
        if (isset(self::$cache[$className])) {
            return self::$cache[$className];
        }

        if (! class_exists($className)) {
            return self::$cache[$className] = false;
        }

        $reflectionClass = new ReflectionClass($className);

        return self::$cache[$className] = $reflectionClass->getAttributes(DecisionModel::class) !== [];
    }

    public static function backingAggregateOf(string $className): ?string
    {
        if (array_key_exists($className, self::$backingAggregateCache)) {
            return self::$backingAggregateCache[$className];
        }

        if (! self::isDecisionModel($className)) {
            return self::$backingAggregateCache[$className] = null;
        }

        return self::$backingAggregateCache[$className] = (new ReflectionClass($className))->getAttributes(DecisionModel::class)[0]->newInstance()->aggregate;
    }
}
