<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\DecisionModel;
use ReflectionClass;

/**
 * licence Enterprise
 */
final class DecisionModelReflection
{
    private static array $cache = [];

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
}
