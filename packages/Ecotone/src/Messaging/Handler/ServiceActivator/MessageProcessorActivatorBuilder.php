<?php

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\AroundMessageProcessor;
use Ecotone\Messaging\Handler\Processor\ChainedMessageProcessor;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\RealMessageProcessor;

class MessageProcessorActivatorBuilder extends InputOutputMessageHandlerBuilder
{
    private ?InterceptedMessageProcessorBuilder $interceptedProcessor = null;

    private array $beforeMessageProcessors = [];
    private array $afterMessageProcessors = [];

    public function __construct(
        private array $processors = [],
        private bool $isReplyRequired = false,
        private ?InterfaceToCall $interceptedInterface = null,
    )
    {
    }

    public static function create(?InterfaceToCall $interceptedInterface = null): self
    {
        return new self(interceptedInterface: $interceptedInterface);
    }

    public function chain(CompilableBuilder $processor): self
    {
        $this->processors[] = $processor;

        return $this;
    }

    public function chainInterceptedProcessor(InterceptedMessageProcessorBuilder $processor): self
    {
        if ($this->interceptedProcessor) {
            throw ConfigurationException::create("Intercepted processor is already set");
        }
        $this->interceptedProcessor = $processor;

        return $this->chain($processor);
    }

    public function registerInterceptors(InterfaceToCallRegistry $interfaceToCallRegistry, array $beforeInterceptors, array $aroundInterceptors, array $afterInterceptors): self
    {
        $missingInterceptorsNames = $this->requiredInterceptorReferenceNames;
        $interceptedInterface = $this->getInterceptedInterface($interfaceToCallRegistry);
        $endpointAnnotationsInstances = array_map(
            fn (AttributeDefinition $attributeDefinition) => $attributeDefinition->instance(),
            $this->getEndpointAnnotations()
        );
        foreach ($beforeInterceptors as $interceptor) {
            if (
                $interceptor->doesItCutWith($interceptedInterface, $endpointAnnotationsInstances)
                || in_array($interceptor->getReferenceName(), $this->requiredInterceptorReferenceNames)
            ) {
                $this->beforeMessageProcessors[] = $interceptor->addInterceptedInterfaceToCall($interceptedInterface, $endpointAnnotationsInstances);
                $missingInterceptorsNames = \array_filter($missingInterceptorsNames, fn (string $interceptorName) => $interceptorName !== $interceptor->getReferenceName());
            }
        }

        foreach ($afterInterceptors as $interceptor) {
            if (
                $interceptor->doesItCutWith($interceptedInterface, $endpointAnnotationsInstances)
                || in_array($interceptor->getReferenceName(), $this->requiredInterceptorReferenceNames)
            ) {
                $this->afterMessageProcessors[] = $interceptor->addInterceptedInterfaceToCall($interceptedInterface, $endpointAnnotationsInstances);
                $missingInterceptorsNames = \array_filter($missingInterceptorsNames, fn (string $interceptorName) => $interceptorName !== $interceptor->getReferenceName());
            }
        }

        foreach ($aroundInterceptors as $interceptor) {
            if (
                $interceptor->doesItCutWith($interceptedInterface, $endpointAnnotationsInstances)
                || in_array($interceptor->getReferenceName(), $this->requiredInterceptorReferenceNames)
            ) {
                $this->orderedAroundInterceptors[] = $interceptor->addInterceptedInterfaceToCall($interceptedInterface, $endpointAnnotationsInstances);
                $missingInterceptorsNames = \array_filter($missingInterceptorsNames, fn (string $interceptorName) => $interceptorName !== $interceptor->getReferenceName());
            }
        }

        if ($missingInterceptorsNames) {
            throw ConfigurationException::create("Missing interceptors: " . \implode(", ", $missingInterceptorsNames));
        }

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $compiledProcessors = $this->compileProcessors($builder);
        $processor = match(count($compiledProcessors)) {
            0 => throw ConfigurationException::create("At least one processor should be provided"),
            1 => $compiledProcessors[0],
            default => new Definition(ChainedMessageProcessor::class, [$compiledProcessors])
        };

        return new Definition(
            MessageProcessorActivator::class,
            [
                $this->outputMessageChannelName ? new ChannelReference($this->outputMessageChannelName) : null,
                $processor,
                new Reference(ChannelResolver::class),
                $this->isReplyRequired,
            ]
        );
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        $interceptedInterface = $this->interceptedProcessor?->getInterceptedInterface($interfaceToCallRegistry);
        return $interceptedInterface ?? $interfaceToCallRegistry->getFor(ChainedMessageProcessor::class, "process");
    }

    /**
     * @return array<Definition|Reference>
     * @throws ConfigurationException
     */
    private function compileProcessors(MessagingContainerBuilder $builder): array
    {
        $compiledProcessors = [];
        foreach ($this->processors as $processor) {
            if ($processor === $this->interceptedProcessor) {
                // Add around interceptors
                foreach ($this->orderedAroundInterceptors as $aroundInterceptor) {
                    $processor = $processor->addAroundMethodInterceptor($aroundInterceptor);
                }
            }
            $compiledProcessor = $processor->compile($builder);
            if ($compiledProcessor instanceof Definition) {
                is_a($compiledProcessor->getClassName(), RealMessageProcessor::class, true)
                || throw new ConfigurationException("Processor should be instance of " . RealMessageProcessor::class . ". Got " . $compiledProcessor->getClassName());
            }
            $compiledProcessors[] = $compiledProcessor;
        }
        return $compiledProcessors;
    }
}