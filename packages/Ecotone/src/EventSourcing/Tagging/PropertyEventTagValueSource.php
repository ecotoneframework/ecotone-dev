<?php

declare(strict_types=1);

namespace Ecotone\EventSourcing\Tagging;

use ReflectionProperty;

/**
 * licence Enterprise
 */
final class PropertyEventTagValueSource implements EventTagValueSource
{
    private readonly ReflectionProperty $reflectionProperty;

    public function __construct(
        private readonly string $tagName,
        string $className,
        string $propertyName,
    ) {
        $this->reflectionProperty = new ReflectionProperty($className, $propertyName);
    }

    public function tagName(): string
    {
        return $this->tagName;
    }

    public function resolveValues(object $event): array
    {
        if (! $this->reflectionProperty->isInitialized($event)) {
            return [];
        }

        return EventTagValueNormalizer::normalize($this->tagName, $this->reflectionProperty->getValue($event));
    }
}
