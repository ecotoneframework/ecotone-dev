<?php

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\Processor\ChainedMessageProcessor;
use Ecotone\Messaging\Handler\RealMessageProcessor;

class MessageProcessorActivatorBuilder extends InputOutputMessageHandlerBuilder
{
    private ?CompilableBuilder $interceptedProcessor = null;

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

    public function chainInterceptedProcessor(CompilableBuilder $processor): self
    {
        if ($this->interceptedProcessor) {
            throw ConfigurationException::create("Intercepted processor is already set");
        }
        $this->interceptedProcessor = $processor;

        return $this->chain($processor);
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $compiledProcessors = \array_map(
            fn(CompilableBuilder $processor) => $processor->compile($builder),
            $this->processors
        );
        foreach ($compiledProcessors as $compiledProcessor) {
            if ($compiledProcessor instanceof Definition) {
                is_a($compiledProcessor->getClassName(), RealMessageProcessor::class, true)
                || throw new ConfigurationException("Processor should be instance of " . RealMessageProcessor::class . ". Got " . $compiledProcessor->getClassName());
            }
        }
        if (count($compiledProcessors) === 0) {
            throw ConfigurationException::create("At least one processor should be provided");
        }
        $processor = count($compiledProcessors) > 1
            ? new Definition(ChainedMessageProcessor::class, [
                $compiledProcessors
            ])
            : $compiledProcessors[0];

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
        return $this->interceptedInterface ?? $interfaceToCallRegistry->getFor(ChainedMessageProcessor::class, "process");
    }
}