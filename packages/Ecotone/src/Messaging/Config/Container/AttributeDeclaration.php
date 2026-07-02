<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Config\Container;

use function array_merge;

use Closure;
use Ecotone\AnnotationFinder\TypeResolver;
use Ecotone\Messaging\Support\InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;

/**
 * licence Apache-2.0
 */
final class AttributeDeclaration
{
    public function __construct(
        private string $attributeClassName,
        private string $className,
        private ?string $methodName = null,
        private ?string $parameterName = null,
        private int $indexAmongSameAttributes = 0,
    ) {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getMethodName(): ?string
    {
        return $this->methodName;
    }

    public function toAttributeDefinition(): AttributeDefinition
    {
        return new AttributeDefinition(
            $this->attributeClassName,
            [$this->attributeClassName, $this->className, $this->methodName, $this->parameterName, $this->indexAmongSameAttributes],
            [self::class, 'resolveAttributeInstance'],
            $this,
        );
    }

    public function toClosureDefinition(): Definition
    {
        return new Definition(
            Closure::class,
            [$this->attributeClassName, $this->className, $this->methodName, $this->parameterName, $this->indexAmongSameAttributes],
            [self::class, 'resolveClosure'],
        );
    }

    public static function resolveAttributeInstance(string $attributeClassName, string $className, ?string $methodName, ?string $parameterName, int $indexAmongSameAttributes): object
    {
        return self::declaredAttributes($attributeClassName, $className, $methodName, $parameterName)[$indexAmongSameAttributes]->newInstance();
    }

    public static function resolveClosure(string $attributeClassName, string $className, ?string $methodName, ?string $parameterName, int $indexAmongSameAttributes): Closure
    {
        $attribute = self::resolveAttributeInstance($attributeClassName, $className, $methodName, $parameterName, $indexAmongSameAttributes);
        $expression = $attribute->getExpression();
        if (! $expression instanceof Closure) {
            throw InvalidArgumentException::create(sprintf('Expected closure expression inside %s attribute declared at %s, got %s', $attributeClassName, $className . ($methodName ? '::' . $methodName : ''), gettype($expression)));
        }

        return $expression;
    }

    /**
     * @return ReflectionAttribute[]
     */
    private static function declaredAttributes(string $attributeClassName, string $className, ?string $methodName, ?string $parameterName): array
    {
        if ($parameterName !== null) {
            return (new ReflectionParameter([$className, $methodName], $parameterName))->getAttributes($attributeClassName);
        }

        $reflectionClass = new ReflectionClass($className);
        if ($methodName !== null) {
            return TypeResolver::getMethodOwnerClass($reflectionClass, $methodName)->getMethod($methodName)->getAttributes($attributeClassName);
        }

        $attributes = [];
        while ($reflectionClass) {
            $attributes = array_merge($attributes, $reflectionClass->getAttributes($attributeClassName));
            $reflectionClass = $reflectionClass->getParentClass();
        }

        return $attributes;
    }
}
