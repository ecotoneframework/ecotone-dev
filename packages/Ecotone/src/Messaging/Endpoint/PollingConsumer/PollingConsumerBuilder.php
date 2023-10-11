<?php

declare(strict_types=1);

namespace Ecotone\Messaging\Endpoint\PollingConsumer;

use Ecotone\Messaging\Attribute\AsynchronousRunningEndpoint;
use Ecotone\Messaging\Channel\DirectChannel;
use Ecotone\Messaging\Channel\MessageChannelBuilder;
use Ecotone\Messaging\Config\Container\AttributeDefinition;
use Ecotone\Messaging\Config\Container\ChannelReference;
use Ecotone\Messaging\Config\Container\ContainerMessagingBuilder;
use Ecotone\Messaging\Config\Container\Definition;
use Ecotone\Messaging\Config\Container\InterfaceToCallReference;
use Ecotone\Messaging\Config\Container\Reference;
use Ecotone\Messaging\Endpoint\InboundChannelAdapterEntrypoint;
use Ecotone\Messaging\Endpoint\InboundGatewayEntrypoint;
use Ecotone\Messaging\Endpoint\MessageHandlerConsumerBuilder;
use Ecotone\Messaging\Handler\ChannelResolver;
use Ecotone\Messaging\Handler\Gateway\GatewayProxyBuilder;
use Ecotone\Messaging\Handler\InterceptedEndpoint;
use Ecotone\Messaging\Handler\InterfaceToCall;
use Ecotone\Messaging\Handler\InterfaceToCallRegistry;
use Ecotone\Messaging\Handler\MessageHandlerBuilder;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\AroundInterceptorReference;
use Ecotone\Messaging\Handler\Processor\MethodInvoker\MethodInterceptor;
use Ecotone\Messaging\Precedence;
use Ecotone\Messaging\Scheduling\Clock;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class PollingConsumerBuilder
 * @package Ecotone\Messaging\Endpoint\PollingConsumer
 * @author Dariusz Gafka <dgafka.mail@gmail.com>
 */
class PollingConsumerBuilder implements MessageHandlerConsumerBuilder, InterceptedEndpoint
{
    private GatewayProxyBuilder $entrypointGateway;
    private string $requestChannelName;
    private ?Reference $compiledGatewayReference = null;

    public function __construct()
    {
        $this->requestChannelName = '_internal_inbound_gateway_request_channel';
        $this->entrypointGateway = GatewayProxyBuilder::create(
            InboundChannelAdapterEntrypoint::class,
            InboundChannelAdapterEntrypoint::class,
            'executeEntrypoint',
            $this->requestChannelName
        )->withEndpointAnnotations([new AttributeDefinition(AsynchronousRunningEndpoint::class, [""])]); // TODO: the endpoint id was passed in here
    }

    public function isPollingConsumer(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function addAroundInterceptor(AroundInterceptorReference $aroundInterceptorReference): self
    {
        $this->entrypointGateway->addAroundInterceptor($aroundInterceptorReference);

        return $this;
    }

    /**
     * @param MethodInterceptor $methodInterceptor
     * @return $this
     */
    public function addBeforeInterceptor(MethodInterceptor $methodInterceptor): self
    {
        $this->entrypointGateway->addBeforeInterceptor($methodInterceptor);

        return $this;
    }

    /**
     * @param MethodInterceptor $methodInterceptor
     * @return $this
     */
    public function addAfterInterceptor(MethodInterceptor $methodInterceptor): self
    {
        $this->entrypointGateway->addAfterInterceptor($methodInterceptor);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getInterceptedInterface(InterfaceToCallRegistry $interfaceToCallRegistry): InterfaceToCall
    {
        return $this->entrypointGateway->getInterceptedInterface($interfaceToCallRegistry);
    }

    /**
     * @inheritDoc
     */
    public function withEndpointAnnotations(iterable $endpointAnnotations): self
    {
        $this->entrypointGateway->withEndpointAnnotations($endpointAnnotations);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getEndpointAnnotations(): array
    {
        return $this->entrypointGateway->getEndpointAnnotations();
    }

    /**
     * @inheritDoc
     */
    public function getRequiredInterceptorNames(): iterable
    {
        return $this->entrypointGateway->getRequiredInterceptorNames();
    }

    /**
     * @inheritDoc
     */
    public function resolveRelatedInterfaces(InterfaceToCallRegistry $interfaceToCallRegistry): iterable
    {
        return array_merge([
            $interfaceToCallRegistry->getFor(InboundGatewayEntrypoint::class, 'executeEntrypoint'),
        ], $this->entrypointGateway->resolveRelatedInterfaces($interfaceToCallRegistry));
    }

    /**
     * @inheritDoc
     */
    public function withRequiredInterceptorNames(iterable $interceptorNames): self
    {
        $this->entrypointGateway->withRequiredInterceptorNames($interceptorNames);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isSupporting(MessageHandlerBuilder $messageHandlerBuilder, MessageChannelBuilder $relatedMessageChannel): bool
    {
        return $relatedMessageChannel->isPollable();
    }

    private function compilePollingConsumerGateway(ContainerMessagingBuilder $builder): Reference
    {
        if ($this->compiledGatewayReference) {
            return $this->compiledGatewayReference;
        }
        $builder->register(PollingConsumerContext::class, [new Reference(Clock::class), new Reference(LoggerInterface::class), new Reference(ContainerInterface::class)]);
        $builder->register(PollingConsumerPostSendAroundInterceptor::class, [new Reference(PollingConsumerContext::class)]);
        $builder->register(PollingConsumerErrorInterceptor::class, [new Reference(PollingConsumerContext::class), new Reference(ChannelResolver::class)]);
        $builder->register(new ChannelReference($this->requestChannelName), new Definition(PollingConsumerChannel::class, [
            new Reference(PollingConsumerContext::class),
        ]));
        // TODO: Add this back in
//        $gatewayBuilder->addAroundInterceptor(AcknowledgeConfirmationInterceptor::createAroundInterceptor(
//            $builder->getInterfaceToCallRegistry(),
//            $pollingMetadata
//        ));
        $gatewayBuilder = clone $this->entrypointGateway;

        return $this->compiledGatewayReference = $gatewayBuilder
            ->addAroundInterceptor(
                AroundInterceptorReference::create(
                    PollingConsumerPostSendAroundInterceptor::class,
                    $builder->getInterfaceToCall(new InterfaceToCallReference(PollingConsumerPostSendAroundInterceptor::class, 'postSend')),
                    Precedence::ASYNCHRONOUS_CONSUMER_INTERCEPTOR_PRECEDENCE,
                )
            )
            ->addAroundInterceptor(
                AroundInterceptorReference::create(
                    PollingConsumerErrorInterceptor::class,
                    $builder->getInterfaceToCall(new InterfaceToCallReference(PollingConsumerErrorInterceptor::class, 'handle')),
                    Precedence::ASYNCHRONOUS_CONSUMER_INTERCEPTOR_PRECEDENCE,
                )
            )
            ->compile($builder);
    }

    public function registerConsumer(ContainerMessagingBuilder $builder, MessageHandlerBuilder $messageHandlerBuilder): void
    {
        $executor = new Definition(PollerTaskExecutor::class, [
            $messageHandlerBuilder->getInputMessageChannelName(),
            new ChannelReference($messageHandlerBuilder->getInputMessageChannelName()),
            $this->compilePollingConsumerGateway($builder)
        ]);

        $messageHandlerReference = $messageHandlerBuilder->compile($builder);
        $builder->register("polling.{$messageHandlerBuilder->getEndpointId()}.executor", $executor);
        $builder->register("polling.{$messageHandlerBuilder->getEndpointId()}.channel", new Definition(DirectChannel::class, ['polling-connection-channel', $messageHandlerReference]));
        // Alias to PollingConsumerContext singleton
        $builder->register("polling.{$messageHandlerBuilder->getEndpointId()}.runner", new Reference(PollingConsumerContext::class));
    }
}
