<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\MessageHandlingException;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\TypeDescriptor;
use Ecotone\Messaging\Message;

/**
 * Class AnnotationInterceptorConverter
 * @package Ecotone\Messaging\Handler\Processor\MethodInvoker
 * @author  Dariusz Gafka <dgafka.mail@gmail.com>
 */
class InterceptorConverter implements ParameterConverter
{
    public function __construct(private InterfaceParameter $parameter, private InterfaceToCall $interceptedInterface, private array $endpointAnnotations)
    {
    }

    /**
     * @inheritDoc
     */
    public function getArgumentFrom(Message $message)
    {
        if ($this->parameter->canBePassedIn(TypeDescriptor::create(InterfaceToCall::class))) {
            return $this->interceptedInterface;
        }

        foreach ($this->endpointAnnotations as $endpointAnnotation) {
            if ($this->parameter->canBePassedIn(TypeDescriptor::createFromVariable($endpointAnnotation))) {
                return $endpointAnnotation;
            }
        }

        if ($this->interceptedInterface->hasMethodAnnotation($this->parameter->getTypeDescriptor())) {
            return $this->interceptedInterface->getMethodAnnotation($this->parameter->getTypeDescriptor());
        }

        if ($this->interceptedInterface->hasClassAnnotation($this->parameter->getTypeDescriptor())) {
            return $this->interceptedInterface->getClassAnnotation($this->parameter->getTypeDescriptor());
        }

        if (! $this->parameter->doesAllowNulls()) {
            throw MessageHandlingException::create("Can find annotation in intercepted {$this->interceptedInterface} to resolve argument {$this->parameter->getName()}. Should not parameter be nullable?");
        }
    }
}
