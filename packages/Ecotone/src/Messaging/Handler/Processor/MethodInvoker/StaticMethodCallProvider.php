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

    public static function getDefinition(string|object $objectDefinition, InterfaceToCall $interfaceToCall, array $parameterConvertersBuilders, ?InterfaceToCall $interceptedInterface = null, array $endpointAnnotations = []): Definition
    {
        if ($interceptedInterface) {
            $parameterConvertersBuilders = MethodArgumentsFactory::createInterceptedInterfaceAnnotationMethodParameters(
                $interfaceToCall,
                $parameterConvertersBuilders,
                $endpointAnnotations,
                $interceptedInterface,
            );
        }
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
}
