<?php

namespace Ecotone\Messaging\Config\Container\Compiler;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\AttributeReference;
use Ecotone\Messaging\Config\Container\ContainerBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceParameterReference;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MethodInterceptionConfiguration;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Handler\RealMessageProcessor;
use Ecotone\Messaging\Handler\ServiceActivator\MessageProcessorActivator;

class HookMethodInterceptorsCompilerPass implements CompilerPass
{
    /**
     * @param array<MethodInterceptor> $beforeInterceptors
     * @param array<AroundInterceptorBuilder> $aroundInterceptors
     * @param array<MethodInterceptor> $afterInterceptors
     */
    public function __construct(
        private InterfaceToCallRegistry $interfaceToCallRegistry,
        private array $beforeInterceptors,
        private array $aroundInterceptors,
        private array $afterInterceptors,
    ) {
    }

    public function process(ContainerBuilder $builder): void
    {
        // TODO: Implement process() method.
    }

    private function processValue($argument, ContainerBuilder $containerBuilder): void
    {
        if ($argument instanceof DefinedObject) {
            $argument = $argument->getDefinition();
        }
        if (is_array($argument)) {
            foreach ($argument as $value) {
                $this->processValue($value, $containerBuilder);
            }
        } elseif ($argument instanceof Definition) {
            $this->hookInterceptors($argument, $containerBuilder);
            $this->processValue($argument->getArguments(), $containerBuilder);
            foreach ($argument->getMethodCalls() as $methodCall) {
                $this->processValue($methodCall->getArguments(), $containerBuilder);
            }
        }
    }

    private function hookInterceptors(Definition $definition, ContainerBuilder $containerBuilder): void
    {
        $interceptorsConfiguration = $definition->getInterceptingConfiguration();
        if (! $interceptorsConfiguration) {
            return;
        }
        $definitionClassName = $definition->getClassName();
        if (is_a($definitionClassName, MessageProcessorActivator::class, true)) {
            // register before interceptors
            $beforeInterceptors = $this->getInterceptorsFor(
                $this->beforeInterceptors,
                $interceptorsConfiguration,
            );

        } else if (is_a($definitionClassName, RealMessageProcessor::class, true)) {
            // register around and after interceptors
        } else {
            throw ConfigurationException::create("Unsupported definition class name {$definitionClassName} to hook interceptors into");
        }
    }

    private function getInterceptorsFor(array $interceptors, MethodInterceptionConfiguration $interceptorsConfiguration): array
    {
        $relatedInterceptors = [];
        $endpointAnnotationsInstances = array_map(
            fn (AttributeDefinition $attributeDefinition) => $attributeDefinition->instance(),
            $interceptorsConfiguration->getEndpointAnnotations(),
        );
        $interceptedInterface = $this->interfaceToCallRegistry->getForReference($interceptorsConfiguration->getInterceptedInterface());
        foreach ($interceptors as $interceptor) {
            foreach ($interceptorsConfiguration->getRequiredInterceptorNames() as $requiredInterceptorName) {
                if ($interceptor->hasName($requiredInterceptorName)) {
                    $relatedInterceptors[] = $interceptor;
                    break;
                }
            }

            if ($interceptor->doesItCutWith($interceptedInterface, $endpointAnnotationsInstances)) {
                $relatedInterceptors[] = $interceptor->addInterceptedInterfaceToCall($interceptedInterface, $interceptorsConfiguration->getEndpointAnnotations());
            }
        }

        return $relatedInterceptors;
    }
}