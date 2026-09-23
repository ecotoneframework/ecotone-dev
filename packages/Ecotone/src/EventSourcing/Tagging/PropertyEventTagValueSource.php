<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use ReflectionProperty;

/**
 * licence Enterprise
 */
final class PropertyEventTagValueSource implements EventTagValueSource
{
    public function __construct(
        private readonly string $tagName,
        private readonly string $propertyName,
    ) {
    }

    public function tagName(): string
    {
        return $this->tagName;
    }

    public function resolveValues(object $event): array
    {
        $reflectionProperty = new ReflectionProperty($event, $this->propertyName);

        if (! $reflectionProperty->isInitialized($event)) {
            return [];
        }

        return EventTagValueNormalizer::normalize($this->tagName, $reflectionProperty->getValue($event));
    }
}
