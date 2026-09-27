<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\EventSourcing\Tagging\EventTagValueNormalizer;
use ReflectionClass;
use ReflectionProperty;

use function array_key_exists;

/**
 * licence Enterprise
 */
final class MessageTagValueResolver
{
    /**
     * @var array<string, ?ReflectionProperty>
     */
    private static array $resolvedAccessors = [];

    public static function resolve(string $tagName, object $payload): ?string
    {
        $property = self::accessorFor($payload::class, $tagName);

        if ($property === null || ! $property->isInitialized($payload)) {
            return null;
        }

        $values = EventTagValueNormalizer::normalize($tagName, $property->getValue($payload));

        return $values[0] ?? null;
    }

    private static function accessorFor(string $payloadClassName, string $tagName): ?ReflectionProperty
    {
        $cacheKey = $payloadClassName . "\0" . $tagName;
        if (array_key_exists($cacheKey, self::$resolvedAccessors)) {
            return self::$resolvedAccessors[$cacheKey];
        }

        return self::$resolvedAccessors[$cacheKey] = self::resolveAccessor($payloadClassName, $tagName);
    }

    private static function resolveAccessor(string $payloadClassName, string $tagName): ?ReflectionProperty
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
