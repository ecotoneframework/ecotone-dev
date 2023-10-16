<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Config\Container\AttributeReference;
use Ecotone\Messaging\Config\Container\CompilableParameterConverterBuilder;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\ReferenceSearchService;

use function get_class;

class AttributeBuilder implements ParameterConverterBuilder
{
    public function __construct(private string $parameterName, private object $attributeInstance, private string $className, private ?string $methodName = null)
    {
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $parameter->getName() === $this->parameterName;
    }

    public function compile(ContainerMessagingBuilder $builder, InterfaceToCall $interfaceToCall, InterfaceParameter $interfaceParameter): Reference|Definition|null
    {
        return new Definition(ValueConverter::class, [new AttributeReference(get_class($this->attributeInstance), $this->className, $this->methodName)]);
    }
}
