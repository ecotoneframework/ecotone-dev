<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\EventTag;
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
final class MessageTagValueResolver
{
    public static function resolve(string $tagName, object $payload): mixed
    {
        $property = self::accessorFor($payload::class, $tagName);

        if ($property === null || ! $property->isInitialized($payload)) {
            return null;
        }

        return $property->getValue($payload);
    }

    public static function canResolve(string $tagName, string $messageClassName): bool
    {
        return self::accessorFor($messageClassName, $tagName) !== null;
    }

    private static function accessorFor(string $payloadClassName, string $tagName): ?ReflectionProperty
    {
        $reflectionClass = new ReflectionClass($payloadClassName);

        foreach ($reflectionClass->getProperties() as $property) {
            foreach ($property->getAttributes(EventTag::class) as $attribute) {
                /** @var EventTag $eventTag */
                $eventTag = $attribute->newInstance();

                if ($eventTag->name === $tagName) {
                    return $property;
                }
            }
        }

        foreach ([$tagName, $tagName . 'Id', $tagName . '_id'] as $candidateName) {
            if ($reflectionClass->hasProperty($candidateName)) {
                return $reflectionClass->getProperty($candidateName);
            }
        }

        return null;
    }
}
