<?php

namespace Ecotone\Messaging\Handler\ServiceActivator;

use Ecotone\Api\Attribute\Collected;
use Ecotone\Api\Attribute\Gathered;
use Ecotone\Messaging\Config\ConfigurationException;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\CompilableBuilder;
use Ecotone\Messaging\Config\Container\DefinedObject;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\MessagingContainerBuilder;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Config\LicenceDecider;
use Ecotone\Messaging\Conversion\ConversionService;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gathering\CollectingMessageHandler;
use Ecotone\Messaging\Handler\Gathering\GatheredGroupStore;
use Ecotone\Messaging\Handler\Gathering\GatheringMessageHandler;
use Ecotone\Messaging\Handler\Gathering\PartCompletion;
use Ecotone\Messaging\Handler\InputOutputMessageHandlerBuilder;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilderWithParameterConverters;
use Ecotone\Messaging\Handler\ParameterConverterBuilder;
use Ecotone\Messaging\Handler\Processor\ChainedMessageProcessor;
use Ecotone\Messaging\Handler\Processor\ChainedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\InterceptedMessageProcessorBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInvokerBuilder;
use Ecotone\Messaging\Handler\RequestReplyProducer;
use Ecotone\Messaging\Handler\Type;
use Ecotone\Messaging\MessageHandler;
use Ecotone\Messaging\Support\Assert;
use Ecotone\Messaging\Support\MessageBuilder;

use function get_class;
use function is_string;

/**
 * @licence Apache-2.0
 */
class MessageProcessorActivatorBuilder extends InputOutputMessageHandlerBuilder implements MessageHandlerBuilderWithParameterConverters
{
    private ChainedMessageProcessorBuilder $chainedMessageProcessorBuilder;

    private function __construct(
        private bool $isReplyRequired = false,
    ) {
        $this->chainedMessageProcessorBuilder = ChainedMessageProcessorBuilder::create();
    }

    public function __clone(): void
    {
        $this->chainedMessageProcessorBuilder = clone $this->chainedMessageProcessorBuilder;
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param Reference|Definition|DefinedObject|object|string $objectToInvokeOn a reference name, a definition, or the object itself
     */
    public static function createForMethod(object|string $objectToInvokeOn, string|InterfaceToCall|InterfaceToCallReference $method): self
    {
        $interfaceToCallReference = match (true) {
            $method instanceof InterfaceToCallReference => $method,
            $method instanceof InterfaceToCall => InterfaceToCallReference::fromInstance($method),
            is_string($objectToInvokeOn) => new InterfaceToCallReference($objectToInvokeOn, $method),
            $objectToInvokeOn instanceof Reference => new InterfaceToCallReference($objectToInvokeOn->getId(), $method),
            $objectToInvokeOn instanceof Definition => new InterfaceToCallReference($objectToInvokeOn->getClassName(), $method),
            default => new InterfaceToCallReference(get_class($objectToInvokeOn), $method),
        };

        return self::createForMethodInvoker(MethodInvokerBuilder::create($objectToInvokeOn, $interfaceToCallReference));
    }

    public static function createForMethodInvoker(MethodInvokerBuilder $methodInvoker): self
    {
        return self::create()->chainInterceptedProcessor($methodInvoker);
    }

    /**
     * @param array<ParameterConverterBuilder> $methodParameterConverterBuilders
     */
    public function withMethodParameterConverters(array $methodParameterConverterBuilders): self
    {
        Assert::allInstanceOfType($methodParameterConverterBuilders, ParameterConverterBuilder::class);
        $this->methodInvoker()->withMethodParameterConverters($methodParameterConverterBuilders);

        return $this;
    }

    /**
     * @return array<ParameterConverterBuilder>
     */
    public function getParameterConverters(): array
    {
        $methodInvoker = $this->chainedMessageProcessorBuilder->getInterceptedProcessor();

        return $methodInvoker instanceof MethodInvokerBuilder ? $methodInvoker->getMethodParameterConverters() : [];
    }

    private function methodInvoker(): MethodInvokerBuilder
    {
        $methodInvoker = $this->chainedMessageProcessorBuilder->getInterceptedProcessor();
        if (! $methodInvoker instanceof MethodInvokerBuilder) {
            throw ConfigurationException::create('Parameter converters can be set only on a handler created with MessageProcessorActivatorBuilder::createForMethod() or createForMethodInvoker(). This handler was created with create() and chains no MethodInvokerBuilder, so there is no method to convert parameters for. Pass the converters to MethodInvokerBuilder::create() and chain it with chainInterceptedProcessor() instead.');
        }

        return $methodInvoker;
    }

    public function withRequiredReply(bool $isReplyRequired): self
    {
        $this->isReplyRequired = $isReplyRequired;

        return $this;
    }

    public function chain(CompilableBuilder $processor): self
    {
        $this->chainedMessageProcessorBuilder->chain($processor);

        return $this;
    }

    public function chainInterceptedProcessor(InterceptedMessageProcessorBuilder $processor): self
    {
        $this->chainedMessageProcessorBuilder->chainInterceptedProcessor($processor);

        return $this;
    }

    public function compile(MessagingContainerBuilder $builder): Definition|Reference
    {
        $chainedMessageProcessorBuilder = $this->chainedMessageProcessorBuilder
            ->withRequiredInterceptorNames($this->getRequiredInterceptorNames())
            ->withEndpointAnnotations($this->getEndpointAnnotations());

        $handler = new Definition(
            RequestReplyProducer::class,
            [
                $this->outputMessageChannelName ? new ChannelReference($this->outputMessageChannelName) : null,
                $chainedMessageProcessorBuilder->compile($builder),
                new Reference(ChannelResolver::class),
                $this->isReplyRequired,
                new Reference(MessageBuilder::class),
                new Reference(PartCompletion::class),
                $chainedMessageProcessorBuilder->getInterceptedInterface()?->getName() ?? '',
            ]
        );
        $interface = $chainedMessageProcessorBuilder->getInterceptedInterface();
        if ($interface !== null && $builder->getInterfaceToCall($interface)->hasMethodAnnotation(Collected::class)) {
            $collected = $builder->getInterfaceToCall($interface)->getSingleAnnotation(Type::create(Collected::class));
            $reference = 'ecotone.collecting.handler.' . $this->getEndpointId();
            $builder->register($reference . '.core', $handler);
            $builder->register($reference . '.enterprise', new Definition(CollectingMessageHandler::class, [
                new Reference($reference . '.core'),
                new Reference(GatheredGroupStore::class),
                new Reference(MessageBuilder::class),
                new Reference(ConversionService::class),
                $this->getEndpointId(),
                $this->getInputMessageChannelName(),
                $collected->maxSize,
            ]));
            return $builder->register($reference, LicenceDecider::prepareDefinition(className: MessageHandler::class, openCoreServiceReference: $reference . '.core', enterpriseServiceReference: $reference . '.enterprise'));
        }
        if ($interface !== null && $builder->getInterfaceToCall($interface)->hasMethodAnnotation(Gathered::class)) {
            $reference = 'ecotone.gathering.handler.' . $this->getEndpointId();
            $builder->register($reference . '.core', $handler);
            $builder->register($reference . '.enterprise', new Definition(GatheringMessageHandler::class, [
                new Reference($reference . '.core'),
                new Reference(GatheredGroupStore::class),
                new Reference(MessageBuilder::class),
                new Reference(ConversionService::class),
                new Reference(ChannelResolver::class),
            ]));
            return $builder->register($reference, LicenceDecider::prepareDefinition(className: MessageHandler::class, openCoreServiceReference: $reference . '.core', enterpriseServiceReference: $reference . '.enterprise'));
        }

        return $handler;
    }

    public function __toString(): string
    {
        $interceptedInterface = $this->chainedMessageProcessorBuilder->getInterceptedInterface();
        if ($interceptedInterface === null) {
            return parent::__toString();
        }

        return sprintf('Handler %s::%s with name `%s` for input channel `%s`', $interceptedInterface->getClassName(), $interceptedInterface->getMethodName(), $this->getEndpointId(), $this->getInputMessageChannelName());
    }

    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        $interceptedInterface = $this->chainedMessageProcessorBuilder->getInterceptedInterface();
        return $interceptedInterface ? $interfaceToCallRegistry->getForReference($interceptedInterface) : $interfaceToCallRegistry->getFor(ChainedMessageProcessor::class, 'process');
    }
}
