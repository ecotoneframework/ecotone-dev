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
    public static function isDecisionModel(string $className): bool
    {
        if (! class_exists($className)) {
            return false;
        }

        return (new ReflectionClass($className))->getAttributes(DecisionModel::class) !== [];
    }

    public static function backingAggregateOf(string $className): ?string
    {
        if (! self::isDecisionModel($className)) {
            return null;
        }

        return (new ReflectionClass($className))->getAttributes(DecisionModel::class)[0]->newInstance()->aggregate;
    }
}
