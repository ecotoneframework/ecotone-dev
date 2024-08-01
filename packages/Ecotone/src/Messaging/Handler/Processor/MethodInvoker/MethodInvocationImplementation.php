<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Handler\InterfaceToCall;

class MethodInvocationImplementation implements MethodInvocation
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private string|object $objectToInvokeOn,
        private string $methodName,
        private array $arguments
    )
    {
    }

    public function proceed(): mixed
    {
        $objectToInvokeOn = $this->getObjectToInvokeOn();
        return is_string($objectToInvokeOn)
            ? $objectToInvokeOn::{$this->getMethodName()}(...$this->getArguments())
            : $objectToInvokeOn->{$this->getMethodName()}(...$this->getArguments());
    }

    public function getObjectToInvokeOn(): string|object
    {
        return $this->objectToInvokeOn;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getInterfaceToCall(): InterfaceToCall
    {
        return InterfaceToCall::create($this->getObjectToInvokeOn(), $this->getMethodName());
    }

    public function getName(): string
    {
        $object = $this->getObjectToInvokeOn();
        $classname = is_string($object) ? $object : get_class($object);
        return "{$classname}::{$this->getMethodName()}";
    }

    public function getArguments(): array
    {
        return array_values($this->arguments);
    }

    public function replaceArgument(string $parameterName, $value): void
    {
        if (! isset($this->arguments[$parameterName])) {
            throw new \InvalidArgumentException("Parameter with name `{$parameterName}` does not exist");
        }
        $this->arguments[$parameterName] = $value;
    }
}