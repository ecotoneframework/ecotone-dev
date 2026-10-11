<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\EventSourcing\Stream;
use Ecotone\Modelling\AggregateFlow\SaveAggregate\AggregateResolver\AggregateDefinitionResolver;
use ReflectionClass;
use ReflectionMethod;

use function class_exists;
use function get_class;
use function is_object;

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
final class DecisionModelStreamResolver
{
    public static function resolveFor(string|object $objectToInvokeOn, string $methodName): string
    {
        if (! class_exists(Stream::class)) {
            return AggregateDefinitionResolver::DEFAULT_STREAM;
        }

        $className = is_object($objectToInvokeOn) ? get_class($objectToInvokeOn) : $objectToInvokeOn;

        $methodReflection = new ReflectionMethod($className, $methodName);
        foreach ($methodReflection->getAttributes(Stream::class) as $attribute) {
            return $attribute->newInstance()->getTableName();
        }

        $classReflection = new ReflectionClass($className);
        foreach ($classReflection->getAttributes(Stream::class) as $attribute) {
            return $attribute->newInstance()->getTableName();
        }

        return AggregateDefinitionResolver::DEFAULT_STREAM;
    }
}
