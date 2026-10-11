<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use ReflectionClass;
use ReflectionProperty;

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
