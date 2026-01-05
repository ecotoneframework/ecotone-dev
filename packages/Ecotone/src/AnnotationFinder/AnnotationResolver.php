<?php

namespace Ecotone\AnnotationFinder;

/**
 * licence Apache-2.0
 */
interface AnnotationResolver
{
    /**
     * @return object[]
     */
    public function getAnnotationsForMethod(string $className, string $methodName): array;

    /**
     * @param class-string $className
     * @param class-string|null $attributeClassName
     * @return list<object>
     */
    public function getAnnotationsForClass(string $className, ?string $attributeClassName): array;

    /**
     * @return object[]
     */
    public function getAnnotationsForProperty(string $className, string $propertyName): array;
}
