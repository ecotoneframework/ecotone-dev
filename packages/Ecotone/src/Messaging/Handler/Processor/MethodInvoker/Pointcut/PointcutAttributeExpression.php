<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Pointcut;

use Ecotone\Messaging\Handler\ClassDefinition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\PointcutExpression;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Support\InvalidArgumentException;

class PointcutAttributeExpression implements PointcutExpression
{

    public function __construct(private ClassDefinition $classDefinition)
    {
        if (! $classDefinition->isAnnotation()) {
            throw InvalidArgumentException::create("Pointcut must be an attribute. Got {$classDefinition->getClassType()->toString()}");
        }
    }

    public function doesItCutWith(array $endpointAnnotations, InterfaceToCall $interfaceToCall): bool
    {
        $annotationToCheck = $this->classDefinition->getClassType();

        foreach ($endpointAnnotations as $endpointAnnotation) {
            $endpointType = TypeDescriptor::createFromVariable($endpointAnnotation);

            if ($endpointType->equals($annotationToCheck)) {
                return true;
            }
        }

        if ($interfaceToCall->hasMethodAnnotation($annotationToCheck)
            || $interfaceToCall->hasClassAnnotation($annotationToCheck)) {
            return true;
        }

        return false;
    }
}