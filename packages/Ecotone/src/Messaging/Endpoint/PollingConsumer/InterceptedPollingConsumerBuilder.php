<?php

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\PollingMetadataReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\AcknowledgeConfirmationInterceptor;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InterceptedConsumerRunner;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterceptedEndpoint;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Scheduling\Clock;

abstract class InterceptedPollingConsumerBuilder implements MessageHandlerConsumerBuilder, InterceptedEndpoint
{
    private array $aroundInterceptorReferences = [];
    private array $beforeInterceptors = [];
    private array $afterInterceptors = [];
    private array $endpointAnnotations = [];

    /**
     * @inheritDoc
     */
    public function addAroundInterceptor(AroundInterceptorReference $aroundInterceptorReference): self
    {
        $this->aroundInterceptorReferences[] = $aroundInterceptorReference;

        return $this;
    }

    /**
     * @param MethodInterceptor $methodInterceptor
     * @return $this
     */
    public function addBeforeInterceptor(MethodInterceptor $methodInterceptor): self
    {
        $this->beforeInterceptors[] = $methodInterceptor;

        return $this;
    }

    /**
     * @param MethodInterceptor $methodInterceptor
     * @return $this
     */
    public function addAfterInterceptor(MethodInterceptor $methodInterceptor): self
    {
        $this->afterInterceptors[] = $methodInterceptor;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function withEndpointAnnotations(iterable $endpointAnnotations): self
    {
        $this->endpointAnnotations = $endpointAnnotations;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointAnnotations(): array
    {
        return $this->endpointAnnotations;
    }

    /**
     * @inheritDoc
     */
    public function getRequiredInterceptorNames(): iterable
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function withRequiredInterceptorNames(iterable $interceptorNames): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isSupporting(MessageHandlerBuilder $messageHandlerBuilder, MessageChannelBuilder $relatedMessageChannel): bool
    {
        return $relatedMessageChannel->isPollable();
    }

    public function isPollingConsumer(): bool
    {
        return true;
    }

    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder): void
    {
        $requestChannelName = 'internal_inbound_gateway_channel.'.Uuid::uuid4()->toString();
        $connectionChannel = new Definition(DirectChannel::class, [
            $requestChannelName,
            $messageHandlerBuilder->compile($builder),
        ]);
        $builder->register(new ChannelReference($requestChannelName), $connectionChannel);
        $gatewayBuilder = GatewayProxyBuilder::create(
            'handler',
            InboundChannelAdapterEntrypoint::class,
            'executeEntrypoint',
            $requestChannelName
        );
        $gatewayBuilder->withEndpointAnnotations(array_merge(
            $this->endpointAnnotations,
            [new AttributeDefinition(AsynchronousRunningEndpoint::class, [$messageHandlerBuilder->getEndpointId()])]
        ));
        foreach ($this->beforeInterceptors as $beforeInterceptor) {
            $gatewayBuilder->addBeforeInterceptor($beforeInterceptor);
        }
        foreach ($this->aroundInterceptorReferences as $aroundInterceptorReference) {
            $gatewayBuilder->addAroundInterceptor($aroundInterceptorReference);
        }
        $gatewayBuilder
            ->addAroundInterceptor($this->getErrorInterceptorReference($builder))
            ->addAroundInterceptor(AcknowledgeConfirmationInterceptor::createAroundInterceptor($builder->getInterfaceToCallRegistry()));
        foreach ($this->afterInterceptors as $afterInterceptor) {
            $gatewayBuilder->addAfterInterceptor($afterInterceptor);
        }

        $gateway = $gatewayBuilder->compile($builder);

        $consumerRunner = new Definition(InterceptedConsumerRunner::class, [
            $gateway,
            $this->compileMessagePoller($builder, $messageHandlerBuilder),
            new PollingMetadataReference($messageHandlerBuilder->getEndpointId()),
            new Reference(Clock::class),
            new Reference(LoggerInterface::class),
        ]);
        $builder->registerPollingEndpoint($messageHandlerBuilder->getEndpointId(), $consumerRunner);
    }

    abstract protected function compileMessagePoller(ContainerMessagingBuilder $builder,  MessageHandlerBuilder $messageHandlerBuilder): Definition|Reference;

    private function getErrorInterceptorReference(ContainerMessagingBuilder $builder): AroundInterceptorReference
    {
        if (!$builder->has(PollingConsumerErrorChannelInterceptor::class)) {
            $builder->register(PollingConsumerErrorChannelInterceptor::class, new Definition(PollingConsumerErrorChannelInterceptor::class, [
                new Reference(ChannelResolver::class),
            ]));
        }
        return AroundInterceptorReference::create(
            PollingConsumerErrorChannelInterceptor::class,
            $builder->getInterfaceToCall(new InterfaceToCallReference(PollingConsumerErrorChannelInterceptor::class, "handle")),
            Precedence::ERROR_CHANNEL_PRECEDENCE,
        );
    }
}