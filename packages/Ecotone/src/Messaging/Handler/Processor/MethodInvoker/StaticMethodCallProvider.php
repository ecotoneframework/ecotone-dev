<?php

namespace Ecotone\Messaging\Handler\Processor\MethodInvoker;

use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\ParameterConverter;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Message;

class StaticMethodCallProvider implements MethodCallProvider
{
    /**
     * @param string $className
     * @param string $methodName
     * @param array<ParameterConverter> $methodParameterConverters
     */
    public function __construct(
        private object|string $objectToInvokeOn,
        private string $methodName,
        private array $methodParameterConverters,
        private array $methodParameterNames,
    ) {
    }

    public function getMethodInvocation(Message $message): MethodInvocation
    {
        $methodArguments = [];
        $count = count($this->methodParameterConverters);

        for ($index = 0; $index < $count; $index++) {
            $parameterName = $this->methodParameterNames[$index];
            $data = $this->methodParameterConverters[$index]->getArgumentFrom($message);

            $methodArguments[$parameterName] = $data;
        }
        return new MethodInvocationImplementation(
            $this->objectToInvokeOn,
            $this->methodName,
            $methodArguments,
        );
    }

    public static function getDefinition(string|object $objectDefinition, InterfaceToCall $interfaceToCall, array $methodParameterConverters, ?InterfaceToCall $interceptedInterface = null, array $endpointAnnotations = []): Definition
    {
        $parameterConvertersBuilders = MethodArgumentsFactory::createDefaultMethodParameters($interfaceToCall, $methodParameterConverters);
        if ($interceptedInterface) {
            $parameterConvertersBuilders = MethodArgumentsFactory::createInterceptedInterfaceAnnotationMethodParameters(
                $interfaceToCall,
                $parameterConvertersBuilders,
                $endpointAnnotations,
                $interceptedInterface,
            );
        }
        $parameterConverters = \array_map(
            fn(ParameterConverterBuilder $parameterConverterBuilder) => $parameterConverterBuilder->compile($interfaceToCall),
            $parameterConvertersBuilders
        );
        return new Definition(self::class, [
            $objectDefinition,
            $interfaceToCall->getMethodName(),
            $parameterConverters,
            $interfaceToCall->getInterfaceParametersNames(),
        ]);
    }
}