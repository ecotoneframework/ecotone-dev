<?php

declare(strict_types=1);

namespace Ecotone\Modelling\DecisionModel;

use Ecotone\Api\Attribute\EventTag;
use Ecotone\EventSourcing\Tagging\EventTagValueNormalizer;
use ReflectionClass;
use ReflectionProperty;

/**
 * licence Enterprise
 */
final class MessageTagValueResolver
{
    public static function resolve(string $tagName, object $payload): ?string
    {
        $reflectionClass = new ReflectionClass($payload);

        foreach ($reflectionClass->getProperties() as $property) {
            foreach ($property->getAttributes(EventTag::class) as $attribute) {
                /** @var EventTag $eventTag */
                $eventTag = $attribute->newInstance();

                if ($eventTag->name === $tagName) {
                    return self::readProperty($property, $payload, $tagName);
                }
            }
        }

        foreach ([$tagName, $tagName . 'Id', $tagName . '_id'] as $candidateName) {
            if ($reflectionClass->hasProperty($candidateName)) {
                return self::readProperty($reflectionClass->getProperty($candidateName), $payload, $tagName);
            }
        }

        return null;
    }

    private static function readProperty(ReflectionProperty $property, object $payload, string $tagName): ?string
    {
        if (! $property->isInitialized($payload)) {
            return null;
        }

        $values = EventTagValueNormalizer::normalize($tagName, $property->getValue($payload));

        return $values[0] ?? null;
    }
}
