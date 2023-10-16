<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker\Converter;

use Ecotone\Messaging\Config\Container\ConfigurationVariableReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\InterfaceParameter;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;

class ConfigurationVariableBuilder implements ParameterConverterBuilder
{
    private function __construct(private string $parameterName, private string $variableName, private bool $isRequired, private mixed $defaultValue)
    {
    }

    public static function create(string $parameterName, string $variableName, bool $isRequired, mixed $defaultValue): self
    {
        return new self($parameterName, $variableName, $isRequired, $defaultValue);
    }

    public static function createFrom(?string $variableName, InterfaceParameter $interfaceParameter): self
    {
        return new self($interfaceParameter->getName(), $variableName ?: $interfaceParameter->getName(), ! $interfaceParameter->doesAllowNulls() && ! $interfaceParameter->hasDefaultValue(), $interfaceParameter->hasDefaultValue() ? $interfaceParameter->getDefaultValue() : null);
    }

    public function isHandling(InterfaceParameter $parameter): bool
    {
        return $this->parameterName === $parameter->getName();
    }

    public function compile(ContainerMessagingBuilder $builder, InterfaceToCall $interfaceToCall): Definition
    {
        return new Definition(ValueConverter::class, [new ConfigurationVariableReference($this->variableName, $this->isRequired, $this->defaultValue)]);
    }
}
