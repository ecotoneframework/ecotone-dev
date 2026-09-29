<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use ReflectionClass;
use ReflectionProperty;

/**
 * licence Enterprise
 */
final class MessageAggregateIdentifierResolver
{
    public static function resolve(string $identifierName, object $payload): mixed
    {
        $property = self::accessorFor($payload::class, $identifierName);

        if ($property === null || ! $property->isInitialized($payload)) {
            return null;
        }

        return $property->getValue($payload);
    }

    public static function canResolve(string $identifierName, string $messageClassName): bool
    {
        return self::accessorFor($messageClassName, $identifierName) !== null;
    }

    /**
     * @return string[]
     */
    public static function candidatePropertyNamesFor(string $identifierName): array
    {
        return [$identifierName, $identifierName . 'Id', $identifierName . '_id'];
    }

    private static function accessorFor(string $messageClassName, string $identifierName): ?ReflectionProperty
    {
        $reflectionClass = new ReflectionClass($messageClassName);

        foreach (self::candidatePropertyNamesFor($identifierName) as $candidateName) {
            if ($reflectionClass->hasProperty($candidateName)) {
                return $reflectionClass->getProperty($candidateName);
            }
        }

        return null;
    }
}
