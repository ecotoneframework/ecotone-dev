<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use function array_map;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Message;

/**
 * @licence Apache-2.0
 */
class StaticMethodInvoker implements MethodInvoker, AroundInterceptable
{
    /**
     * @param array<ParameterConverter> $methodParameterConverters
     */
    public function __construct(
        private object|string $objectToInvokeOn,
        private string $methodName,
        private array $methodParameterConverters,
        private array $methodParameterNames,
    ) {
    }

    public function execute(Message $message): mixed
    {
        $objectToInvokeOn = $this->getObjectToInvokeOn($message);
        return is_string($objectToInvokeOn)
            ? $objectToInvokeOn::{$this->getMethodName()}(...$this->getArguments($message))
            : $objectToInvokeOn->{$this->getMethodName()}(...$this->getArguments($message));
    }

    public static function getDefinition(string|object $objectDefinition, InterfaceToCall $interfaceToCall, array $parameterConvertersBuilders): Definition
    {
        $parameterConvertersBuilders = MethodArgumentsFactory::createDefaultMethodParameters($interfaceToCall, $parameterConvertersBuilders);
        $parameterConverters = array_map(
            fn (ParameterConverterBuilder $parameterConverterBuilder) => $parameterConverterBuilder->compile($interfaceToCall),
            $parameterConvertersBuilders
        );
        return new Definition(self::class, [
            $objectDefinition,
            $interfaceToCall->getMethodName(),
            $parameterConverters,
            $interfaceToCall->getInterfaceParametersNames(),
        ]);
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getObjectToInvokeOn(Message $message): string|object
    {
        return $this->objectToInvokeOn;
    }

    public function getArguments(Message $message): array
    {
        $methodArguments = [];
        $count = count($this->methodParameterConverters);

        for ($index = 0; $index < $count; $index++) {
            $parameterName = $this->methodParameterNames[$index];
            $data = $this->methodParameterConverters[$index]->getArgumentFrom($message);

            $methodArguments[$parameterName] = $data;
        }
        return $methodArguments;
    }
}
