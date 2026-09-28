<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\EventTag;
use ReflectionClass;
use ReflectionProperty;

/**
 * licence Enterprise
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
