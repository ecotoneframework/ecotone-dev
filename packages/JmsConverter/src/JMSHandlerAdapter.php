<?php

namespace Ecotone\JMSConverter;

use Closure;
use Ecotone\Messaging\Handler\ReferenceSearchService;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\Assert;
use JMS\Serializer\GraphNavigator;

class JMSHandlerAdapter
{
    /**
     * @var TypeDescriptor
     */
    private $fromType;
    /**
     * @var TypeDescriptor
     */
    private $toType;

    private string|object $referenceNameOrObject;
    /**
     * @var string
     */
    private $methodName;

    public function __construct(TypeDescriptor $fromType, TypeDescriptor $toType, string|object $referenceNameOrObject, string $methodName)
    {
        Assert::isTrue($fromType->isClassOrInterface() || $toType->isClassOrInterface(), 'Atleast one side of converter must be class');
        Assert::isFalse($fromType->isClassOrInterface() && $toType->isClassOrInterface(), 'Both sides of converter cannot to be classes');

        $this->fromType = $fromType;
        $this->toType = $toType;

        $this->referenceNameOrObject = $referenceNameOrObject;
        $this->methodName = $methodName;
    }

    public static function create(TypeDescriptor $fromType, TypeDescriptor $toType, string $referenceName, string $methodName): self
    {
        return new self($fromType, $toType, $referenceName, $methodName);
    }

    public static function createWithDirectObject(TypeDescriptor $fromType, TypeDescriptor $toType, object $referenceObject, string $methodName): self
    {
        return new self($fromType, $toType, $referenceObject, $methodName);
    }

    public function getSerializerClosure(ReferenceSearchService $referenceSearchService): Closure
    {
        $object = is_string($this->referenceNameOrObject) ? $referenceSearchService->get($this->referenceNameOrObject) : $this->referenceNameOrObject;

        return function ($visitor, $data) use ($object) {
            return call_user_func([$object, $this->methodName], $data);
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
