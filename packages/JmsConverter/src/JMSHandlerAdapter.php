<?php

namespace Ecotone\JMSConverter;

use Closure;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\Support\Assert;
use JMS\Serializer\GraphNavigator;

/**
 * licence Apache-2.0
 */
class JMSHandlerAdapter
{
    public function __construct(private Type $fromType, private Type $toType, private object $object, private string $methodName)
    {
        Assert::isTrue($fromType->isClassOrInterface() || $toType->isClassOrInterface(), 'At least one side of converter must be class');
        Assert::isFalse($fromType->isClassOrInterface() && $toType->isClassOrInterface(), 'Both sides of converter cannot to be classes');
    }

    public static function create(Type $fromType, Type $toType, string $referenceName, string $methodName): self
    {
        return new self($fromType, $toType, $referenceName, $methodName);
    }

    public static function createWithDefinition(Type $fromType, Type $toType, Definition $definition, string $methodName): self
    {
        return new self($fromType, $toType, $definition, $methodName);
    }

    public function getSerializerClosure(): Closure
    {
        return function ($visitor, $data) {
            return $this->object->{$this->methodName}($data);
        };
    }

    public function getRelatedClass(): string
    {
        return $this->fromType->isClassOrInterface() ? $this->fromType->toString() : $this->toType->toString();
    }

    public function getDirection(): int
    {
        return $this->fromType->isClassOrInterface() ? GraphNavigator::DIRECTION_SERIALIZATION : GraphNavigator::DIRECTION_DESERIALIZATION;
    }
}
