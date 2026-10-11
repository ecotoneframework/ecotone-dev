<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\DecisionModel;
use ReflectionClass;

/**
 * licence Enterprise
 *
 * Proprietary. Copyright (c) 2025 Dariusz Gafka <support@simplycodedsoftware.com>.
 * Use requires an Ecotone Enterprise licence (trial: https://ecotone.tech/pricing).
 *
 * Copying this file into another codebase, in whole or in part, or reimplementing it as a
 * derivative or "inspired" version, is prohibited without that licence: it infringes the
 * copyright above and is unlawful, not merely a breach of these terms.
 *
 * Changes belong here, as a pull request to github.com/ecotoneframework/ecotone-dev
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
