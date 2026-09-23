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
