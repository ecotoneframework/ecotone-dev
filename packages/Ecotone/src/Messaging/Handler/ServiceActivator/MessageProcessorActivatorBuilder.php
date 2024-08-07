<?php

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\MethodInterceptorsConfiguration;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\ChainedMessageProcessor;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\RealMessageProcessor;

class MessageProcessorActivatorBuilder extends InputOutputMessageHandlerBuilder
{
    private ?InterceptedMessageProcessorBuilder $interceptedProcessor = null;

    public function __construct(
        private array $processors = [],
        private bool $isReplyRequired = false,
    ) {
    }

    public static function create(): self
    {
        return new self();
    }

    public function chain(CompilableBuilder $processor): self
    {
        $this->processors[] = $processor;

        return $this;
    }

    public function chainInterceptedProcessor(InterceptedMessageProcessorBuilder $processor): self
    {
        if ($this->interceptedProcessor) {
            throw ConfigurationException::create('Intercepted processor is already set');
        }
        $this->interceptedProcessor = $processor;

        return $this->chain($processor);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $compiledProcessors = $this->compileProcessors($builder);
        $processor = match(count($compiledProcessors)) {
            0 => throw ConfigurationException::create('At least one processor should be provided'),
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
        $interceptedInterface = $this->interceptedProcessor?->getInterceptedInterface();
        return $interceptedInterface ? $interfaceToCallRegistry->getForReference($interceptedInterface) : $interfaceToCallRegistry->getFor(ChainedMessageProcessor::class, 'process');
    }

    /**
     * @return array<Definition|Reference>
     * @throws ConfigurationException
     */
    private function compileProcessors(MessagingContainerBuilder $builder): array
    {
        $compiledProcessors = [];
        $interceptorsConfiguration = $this->interceptedProcessor
            ? $builder->getRelatedInterceptors(
                $this->interceptedProcessor->getInterceptedInterface(),
                $this->getEndpointAnnotations(),
                $this->getRequiredInterceptorNames()
            )
            : MethodInterceptorsConfiguration::createEmpty();
        // register before interceptors
        foreach ($interceptorsConfiguration->getBeforeInterceptors() as $beforeInterceptor) {
            $compiledProcessors[] = $beforeInterceptor->compileForInterceptedInterface($builder, $this->interceptedProcessor->getInterceptedInterface(), $this->getEndpointAnnotations());
        }
        foreach ($this->processors as $processor) {
            if ($processor === $this->interceptedProcessor) {
                $compiledProcessors[] = $processor->compile($builder, $interceptorsConfiguration);
                // register after interceptors
                foreach ($interceptorsConfiguration->getAfterInterceptors() as $afterInterceptor) {
                    $compiledProcessors[] = $afterInterceptor->compileForInterceptedInterface($builder, $this->interceptedProcessor->getInterceptedInterface(), $this->getEndpointAnnotations());
                }
            } else {
                $compiledProcessors[] = $processor->compile($builder);
            }
        }
        foreach ($compiledProcessors as $compiledProcessor) {
            if ($compiledProcessor instanceof Definition
                && ! is_a($compiledProcessor->getClassName(), RealMessageProcessor::class, true)) {
                throw ConfigurationException::create('Processor should implement ' . RealMessageProcessor::class . " interface, but got {$compiledProcessor->getClassName()}");
            }
        }
        return $compiledProcessors;
    }
}
